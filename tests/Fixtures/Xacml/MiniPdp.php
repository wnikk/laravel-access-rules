<?php

namespace Tests\Fixtures\Xacml;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * A decision point for the part of XACML 3.0 that Exporter writes, and nothing beyond it.
 *
 * The package has no XACML engine on purpose, so something else has to say what an exported
 * document means. This class reads the XML the way the standard describes: Targets, the three
 * combining algorithms the export uses, typed functions over bags, Indeterminate for a missing
 * attribute. It shares no code with the package, which is the point: when both agree on
 * a decision, the agreement is about the document and not about a common mistake.
 *
 * Indeterminate is kept simple: one kind, without the {D}, {P} and {DP} refinements of the
 * standard. They matter when a policy mixes effects, and every policy of the export holds one.
 */
final class MiniPdp
{
    public const PERMIT = 'Permit';

    public const DENY = 'Deny';

    public const NOT_APPLICABLE = 'NotApplicable';

    public const INDETERMINATE = 'Indeterminate';

    /** @var callable(string, string, string): array */
    private $attributes;

    /** @var callable(string): ?bool Runs the one function that is not XACML, "urn:wnikk:access:function:dsl". */
    private $dsl;

    public function __construct(private string $xml) {}

    public function decide(callable $attributes, callable $dsl): string
    {
        $this->attributes = $attributes;
        $this->dsl        = $dsl;

        $doc = new DOMDocument;
        $doc->loadXML($this->xml, LIBXML_NOBLANKS);

        return $this->evaluate($doc->documentElement);
    }

    private function evaluate(DOMElement $e): string
    {
        $target = $this->first($e, 'Target');
        if ($target !== null) {
            try {
                if (! $this->matches($target)) {
                    return self::NOT_APPLICABLE;
                }
            } catch (Missing) {
                return self::INDETERMINATE;
            }
        }

        if ($e->localName === 'Rule') {
            $condition = $this->first($e, 'Condition');
            try {
                if ($condition !== null && $this->value($this->elements($condition)[0]) !== true) {
                    return self::NOT_APPLICABLE;
                }
            } catch (Missing) {
                return self::INDETERMINATE;
            }

            return $e->getAttribute('Effect');
        }

        $results = [];
        foreach ($this->elements($e) as $child) {
            if (in_array($child->localName, ['PolicySet', 'Policy', 'Rule'], true)) {
                $results[] = $this->evaluate($child);
            }
        }

        $algorithm = $e->getAttribute($e->localName === 'Policy' ? 'RuleCombiningAlgId' : 'PolicyCombiningAlgId');

        return match (substr($algorithm, strrpos($algorithm, ':') + 1)) {
            'first-applicable' => array_values(array_filter($results, fn ($r) => $r !== self::NOT_APPLICABLE))[0] ?? self::NOT_APPLICABLE,
            'deny-overrides'   => $this->overrides($results, self::DENY, self::PERMIT),
            'permit-overrides' => $this->overrides($results, self::PERMIT, self::DENY),
            default            => throw new RuntimeException('MiniPdp does not know '.$algorithm),
        };
    }

    private function overrides(array $results, string $strong, string $weak): string
    {
        return match (true) {
            in_array($strong, $results, true)             => $strong,
            in_array(self::INDETERMINATE, $results, true) => self::INDETERMINATE,
            in_array($weak, $results, true)               => $weak,
            default                                       => self::NOT_APPLICABLE,
        };
    }

    private function matches(DOMElement $target): bool
    {
        foreach ($this->elements($target, 'AnyOf') as $anyOf) {
            $any = false;
            foreach ($this->elements($anyOf, 'AllOf') as $allOf) {
                $all = true;
                foreach ($this->elements($allOf, 'Match') as $match) {
                    [$literal, $designator] = $this->elements($match);
                    $wanted                 = $this->value($literal);
                    $all                    = $all && in_array($wanted, $this->value($designator), false);
                }
                $any = $any || $all;
            }
            if (! $any) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return mixed A scalar, or a list for a bag.
     */
    private function value(DOMElement $e): mixed
    {
        $type = substr($e->getAttribute('DataType'), strrpos($e->getAttribute('DataType'), '#') + 1);

        if ($e->localName === 'AttributeValue') {
            return $this->typed(trim($e->textContent), $type);
        }

        if ($e->localName === 'AttributeDesignator') {
            // An attribute is asked for by type. A value of another type is not that attribute: "re" is no double.
            $values = array_filter(
                ($this->attributes)($e->getAttribute('Category'), $e->getAttribute('AttributeId'), $type),
                fn ($v) => ! in_array($type, ['integer', 'double'], true) || is_numeric($v)
            );

            return array_map(fn ($v) => $this->typed($v, $type), array_values($values));
        }

        $function = $e->getAttribute('FunctionId');
        if ($function === 'urn:wnikk:access:function:dsl') {
            return ($this->dsl)(trim($e->textContent)) ?? throw new Missing;
        }

        $name = substr($function, strrpos($function, ':') + 1);
        $args = fn () => array_map(fn (DOMElement $a) => $this->value($a), $this->elements($e));

        // "and" and "or" stop at the first answer, as the standard says, so an Indeterminate behind it is never met.
        if ($name === 'and' || $name === 'or') {
            foreach ($this->elements($e) as $argument) {
                if ($this->value($argument) === ($name === 'or')) {
                    return $name === 'or';
                }
            }

            return $name === 'and';
        }

        $a = $args();

        return match (true) {
            $name === 'not'                                => ! $a[0],
            str_ends_with($name, '-one-and-only')          => count($a[0]) === 1 ? $a[0][0] : throw new Missing,
            str_ends_with($name, '-bag-size')              => count($a[0]),
            str_ends_with($name, '-is-in')                 => in_array($a[0], $a[1], false),
            str_ends_with($name, '-bag')                   => $a,
            str_ends_with($name, '-greater-than-or-equal') => $a[0] >= $a[1],
            str_ends_with($name, '-less-than-or-equal')    => $a[0] <= $a[1],
            str_ends_with($name, '-greater-than')          => $a[0] > $a[1],
            str_ends_with($name, '-less-than')             => $a[0] < $a[1],
            str_ends_with($name, '-equal')                 => $a[0] === $a[1],
            $name === 'double-add'                         => $a[0] + $a[1],
            $name === 'double-subtract'                    => $a[0] - $a[1],
            $name === 'double-multiply'                    => $a[0] * $a[1],
            $name === 'double-divide'                      => $a[1] == 0 ? throw new Missing : $a[0] / $a[1],
            $name === 'integer-to-double'                  => (float) $a[0],
            $name === 'integer-from-string'                => (int) $a[0],
            $name === 'double-from-string'                 => (float) $a[0],
            $name === 'string-starts-with'                 => str_starts_with($a[1], $a[0]),
            $name === 'string-ends-with'                   => str_ends_with($a[1], $a[0]),
            $name === 'string-contains'                    => str_contains($a[1], $a[0]),
            $name === 'string-normalize-to-lower-case'     => mb_strtolower($a[0]),
            default                                        => throw new RuntimeException('MiniPdp does not know '.$function),
        };
    }

    private function typed(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer'  => (int) $value,
            'double'   => (float) $value,
            'boolean'  => is_bool($value) ? $value : in_array($value, ['true', '1', 1], true),
            'dateTime' => str_replace(' ', 'T', (string) $value),
            default    => (string) $value,
        };
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(DOMElement $e, ?string $name = null): array
    {
        $found = [];
        foreach ($e->childNodes as $child) {
            if ($child instanceof DOMElement && ($name === null || $child->localName === $name)) {
                $found[] = $child;
            }
        }

        return $found;
    }

    private function first(DOMElement $e, string $name): ?DOMElement
    {
        return $this->elements($e, $name)[0] ?? null;
    }
}
