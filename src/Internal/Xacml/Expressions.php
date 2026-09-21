<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Xacml;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Wnikk\LaravelAccessRules\Exceptions\UntranslatableConditionException;
use Wnikk\LaravelAccessRules\Internal\Conditions\ResourceRegistry;
use Wnikk\LaravelAccessRules\Internal\Conditions\Syntax\Printer;

/**
 * Translates conditions between the tree of the package and expressions of XACML, both ways.
 *
 * The two directions live in one class because they are one table read from two sides: what
 * toXml() writes for "order.cost > 100" is what toText() must recognise. Exporter and Importer
 * of this module are the callers; nothing in the core knows the class.
 *
 * On the way out the class works on the stored tree. On the way in it produces text of the
 * package, not a tree, and ConditionCompiler compiles that text like any other. So a condition
 * from a foreign document passes the same checks as one typed by an administrator, models from
 * config access.resources included, and the module has no way to store what the core would refuse.
 *
 * XACML is typed and the package is not. Types come from the schema of the database, then from
 * literals, and text is the fallback. What XACML has no words for, filtered aggregates, sums,
 * tree and time functions, functions of the application, goes out as text of the package inside
 * the function "urn:wnikk:access:function:dsl". A foreign engine cannot run it, and the
 * exporter says so; the package reads it back without loss.
 *
 * Unknown is where the two models part. A missing attribute is "unknown" for the package and
 * the permission is skipped. In XACML it is Indeterminate, and "first-applicable" stops there.
 * With a PEP that denies on Indeterminate, which the standard asks for, the exported policy is
 * never more permissive than the package, and it is stricter for records with NULL in a
 * compared column. Encoding three-valued logic exactly doubles every condition and makes it
 * unreadable, which defeats the reason to export.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
final class Expressions
{
    /** @var array<string, string> AttributeId => path of the package, from config access.xacml.attributes. */
    private array $attributes;

    /** @var array<string, array<string, string>> "connection.table" => column => XACML type. */
    private array $columns = [];

    public function __construct(private ResourceRegistry $resources)
    {
        $this->attributes = config('access.xacml.attributes') ?? [];
    }

    /**
     * @param array        $tree     A stored condition.
     * @param string|null  $alias    Alias of the model the rule is about. Without it types are guessed from literals.
     * @param list<string> $warnings Gets a line for every part that went out as text of the package.
     */
    public function toXml(DOMDocument $document, array $tree, ?string $alias, array &$warnings): DOMElement
    {
        return $this->boolean($document, $tree, $alias, $warnings);
    }

    /**
     * @param array<string, DOMElement> $variables VariableDefinition elements of the policy, by id. References are replaced by what they name.
     *
     * @throws UntranslatableConditionException With the URN or the construct that has no counterpart in the package.
     */
    public function toText(DOMElement $expression, array $variables = []): string
    {
        return $this->text($expression, $variables);
    }

    /**
     * A Match of a Target is a comparison of a designator with a literal, so it converts like one.
     * The importer uses it for matches that name neither a subject nor an action.
     */
    public function matchToText(DOMElement $match): string
    {
        $value      = self::child($match, 'AttributeValue');
        $designator = self::child($match, 'AttributeDesignator');
        if ($value === null || $designator === null) {
            throw new UntranslatableConditionException('a Match without AttributeDesignator (AttributeSelector needs XPath, which the package does not run)');
        }

        // In a Match the literal comes first: MatchId(value, attribute).
        return $this->call(Vocabulary::local($match->getAttribute('MatchId')), [$this->literal($value), $this->path($designator)], [$value, $designator]);
    }

    // ------------------------------------------------------------------ tree to XML

    private function boolean(DOMDocument $doc, array $node, ?string $alias, array &$warnings): DOMElement
    {
        if ($node[0] === 'and' || $node[0] === 'or') {
            return $this->apply($doc, Vocabulary::FN.$node[0], $this->boolean($doc, $node[1], $alias, $warnings), $this->boolean($doc, $node[2], $alias, $warnings));
        }

        if ($node[0] === 'not') {
            return $this->apply($doc, Vocabulary::FN.'not', $this->boolean($doc, $node[1], $alias, $warnings));
        }

        try {
            return $this->predicate($doc, $node, $alias);
        } catch (UntranslatableConditionException $e) {
            $text       = Printer::print($node, $alias ?? 'resource');
            $warnings[] = '"'.$text.'" has no counterpart in XACML ('.$e->getMessage().'); it is exported as text that only this package reads';

            return $this->apply($doc, Vocabulary::FN_DSL, $this->value($doc, 'string', $text));
        }
    }

    private function predicate(DOMDocument $doc, array $node, ?string $alias): DOMElement
    {
        switch ($node[0]) {
            case 'val':
                if (! is_bool($node[1])) {
                    throw new UntranslatableConditionException('a value that is not a boolean stands as a condition');
                }

                return $this->value($doc, 'boolean', $node[1]);

            case 'is-author':
                return $this->apply(
                    $doc,
                    Vocabulary::FN.'string-equal',
                    $this->one($doc, 'string', $this->designator($doc, Vocabulary::RESOURCE, Vocabulary::RESOURCE_AUTHOR, 'string')),
                    $this->one($doc, 'string', $this->designator($doc, Vocabulary::SUBJECT, Vocabulary::SUBJECT_ID, 'string')),
                );

            case 'agg':
                if ($node[1] !== 'exists' || $node[5] !== null) {
                    throw new UntranslatableConditionException('an aggregate with a filter');
                }

                return $this->apply($doc, Vocabulary::FN.'not', $this->apply($doc, Vocabulary::FN.'integer-equal', $this->bagSize($doc, $node, $alias), $this->value($doc, 'integer', 0)));

            case 'str':
                if ($node[1] === 'lower') {
                    throw new UntranslatableConditionException('lower() stands as a condition');
                }

                return $this->apply($doc, Vocabulary::FN3.Vocabulary::TEXT[$node[1]], $this->operand($doc, $node[3], 'string', $alias), $this->operand($doc, $node[2], 'string', $alias));

            case 'in':
                $type = $this->typeOf($node[1], $alias) ?? $this->typeOfList($node[2]) ?? 'string';
                $list = $node[2][0] === 'val' && is_array($node[2][1])
                    ? $this->apply($doc, Vocabulary::FN.$type.'-bag', ...array_map(fn ($item) => $this->value($doc, $type, $item), $node[2][1]))
                    : $this->bagOf($doc, $node[2], $type);

                $found = $this->apply($doc, Vocabulary::FN.$type.'-is-in', $this->operand($doc, $node[1], $type, $alias), $list);

                return $node[3] ? $this->apply($doc, Vocabulary::FN.'not', $found) : $found;

            case 'cmp':
                return $this->comparison($doc, $node, $alias);
        }

        throw new UntranslatableConditionException($node[0] === 'call' ? 'function '.$node[1].'()' : 'node "'.$node[0].'"');
    }

    private function comparison(DOMDocument $doc, array $node, ?string $alias): DOMElement
    {
        [, $op, $left, $right] = $node;

        // XACML has no NULL. An attribute that is not there is an empty bag, so "is null" asks for its size.
        if ($right === ['val', null]) {
            if (! in_array($left[0], ['res', 'attr'], true)) {
                throw new UntranslatableConditionException('a comparison of an expression with null');
            }
            $type  = $this->typeOf($left, $alias) ?? 'string';
            $empty = $this->apply($doc, Vocabulary::FN.'integer-equal', $this->apply($doc, Vocabulary::FN.$type.'-bag-size', $this->bare($doc, $left, $type, $alias)), $this->value($doc, 'integer', 0));

            return $op === '==' ? $empty : $this->apply($doc, Vocabulary::FN.'not', $empty);
        }

        $l    = $this->typeOf($left, $alias);
        $r    = $this->typeOf($right, $alias);
        $type = match (true) {
            $l === null || $r === null || $l === $r                                                => $l ?? $r ?? 'string',
            in_array($l, ['integer', 'double'], true) && in_array($r, ['integer', 'double'], true) => 'double',
            default                                                                                => throw new UntranslatableConditionException('a comparison of '.$l.' with '.$r),
        };

        if ($type === 'boolean' && ! in_array($op, ['==', '!='], true)) {
            throw new UntranslatableConditionException('an ordering of booleans');
        }

        $equal = $this->apply(
            $doc,
            Vocabulary::FN.$type.'-'.Vocabulary::COMPARISONS[$op === '!=' ? '==' : $op],
            $this->operand($doc, $left, $type, $alias),
            $this->operand($doc, $right, $type, $alias),
        );

        return $op === '!=' ? $this->apply($doc, Vocabulary::FN.'not', $equal) : $equal;
    }

    /**
     * One value of the wanted type. An integer where a double is wanted is converted, because
     * XACML refuses to compare or add the two.
     */
    private function operand(DOMDocument $doc, array $node, string $type, ?string $alias): DOMElement
    {
        if ($node[0] === 'val') {
            if (is_array($node[1]) || $node[1] === null) {
                throw new UntranslatableConditionException('a list or null used as one value');
            }

            return $this->value($doc, $type, $node[1]);
        }

        $own = $this->typeOf($node, $alias) ?? $type;

        $element = match ($node[0]) {
            'res'  => $this->one($doc, $own, $this->bare($doc, $node, $own, $alias)),
            'attr' => $this->subjectOrEnvironment($doc, $node, $own),
            'agg'  => $node[1] === 'count' && $node[5] === null ? $this->bagSize($doc, $node, $alias) : throw new UntranslatableConditionException($node[1].'() over a relation'.($node[5] === null ? '' : ' with a filter')),
            'math' => $this->apply($doc, Vocabulary::FN.'double-'.Vocabulary::ARITHMETIC[$node[1]], $this->operand($doc, $node[2], 'double', $alias), $this->operand($doc, $node[3], 'double', $alias)),
            'str'  => $node[1] === 'lower' ? $this->apply($doc, Vocabulary::FN.'string-normalize-to-lower-case', $this->operand($doc, $node[2], 'string', $alias)) : throw new UntranslatableConditionException($node[1].'() used as a value'),
            'call' => match ($node[1]) {
                'now'   => $this->one($doc, 'dateTime', $this->designator($doc, Vocabulary::ENVIRONMENT, Vocabulary::ENVIRONMENT_ATTRIBUTES['now'][0], 'dateTime')),
                'today' => $this->one($doc, 'date', $this->designator($doc, Vocabulary::ENVIRONMENT, Vocabulary::ENVIRONMENT_ATTRIBUTES['today'][0], 'date')),
                default => throw new UntranslatableConditionException('function '.$node[1].'()'),
            },
            default => throw new UntranslatableConditionException('node "'.$node[0].'" used as a value'),
        };

        if ($own === $type) {
            return $element;
        }
        if ($own === 'integer' && $type === 'double') {
            return $this->apply($doc, Vocabulary::FN.'integer-to-double', $element);
        }

        throw new UntranslatableConditionException('a value of type '.$own.' where '.$type.' is needed');
    }

    /**
     * The key of the user is a string in every XACML request, and the columns it is compared
     * with are numbers more often than not. The conversion function bridges the two.
     */
    private function subjectOrEnvironment(DOMDocument $doc, array $node, string $type): DOMElement
    {
        if ($node[1] === 'subject' && $node[2] === 'id' && $type !== 'string') {
            if (! in_array($type, ['integer', 'double'], true)) {
                throw new UntranslatableConditionException('the key of the user compared with a '.$type);
            }

            return $this->apply($doc, Vocabulary::FN3.$type.'-from-string', $this->one($doc, 'string', $this->designator($doc, Vocabulary::SUBJECT, Vocabulary::SUBJECT_ID, 'string')));
        }

        return $this->one($doc, $type, $this->bare($doc, $node, $type, null));
    }

    /**
     * A designator without "one-and-only" around it: a bag, as XACML sees every attribute.
     */
    private function bare(DOMDocument $doc, array $node, string $type, ?string $alias): DOMElement
    {
        if ($node[0] === 'res') {
            return $this->designator($doc, Vocabulary::RESOURCE, $this->attributeId(($alias ?? 'resource').'.'.implode('.', [...$node[1], $node[2]])), $type);
        }

        [, $category, $path] = $node;

        return match (true) {
            $category === 'subject' && $path === 'id'                               => $this->designator($doc, Vocabulary::SUBJECT, Vocabulary::SUBJECT_ID, 'string'),
            $category === 'subject' && $path === 'roles'                            => throw new UntranslatableConditionException('user.roles holds ids of records of this database'),
            $category === 'subject'                                                 => $this->designator($doc, Vocabulary::SUBJECT, $this->attributeId('user.'.$path), $type),
            $category === 'env' && isset(Vocabulary::ENVIRONMENT_ATTRIBUTES[$path]) => $this->designator($doc, Vocabulary::ENVIRONMENT, Vocabulary::ENVIRONMENT_ATTRIBUTES[$path][0], Vocabulary::ENVIRONMENT_ATTRIBUTES[$path][1]),
            $category === 'env'                                                     => $this->designator($doc, Vocabulary::ENVIRONMENT, $this->attributeId('env.'.$path), $type),
            $category === 'action' && $path === 'id'                                => $this->designator($doc, Vocabulary::ACTION, Vocabulary::ACTION_ID, 'string'),
            default                                                                 => throw new UntranslatableConditionException('attribute '.$category.'.'.$path),
        };
    }

    private function bagOf(DOMDocument $doc, array $node, string $type): DOMElement
    {
        if ($node[0] !== 'attr') {
            throw new UntranslatableConditionException('a list that is neither literal nor an attribute');
        }

        return $this->bare($doc, $node, $type, null);
    }

    /**
     * Related records are a bag of their ids, typed anyURI on purpose. The type is how the
     * importer tells "count(order.items) == 0" from "order.cost == null": both ask for the size
     * of a bag, and only the first one asks a bag of anyURI.
     */
    private function bagSize(DOMDocument $doc, array $node, ?string $alias): DOMElement
    {
        $id = $this->attributeId(($alias ?? 'resource').'.'.implode('.', [...$node[2], $node[3]]));

        return $this->apply($doc, Vocabulary::FN.'anyURI-bag-size', $this->designator($doc, Vocabulary::RESOURCE, $id, 'anyURI'));
    }

    private function typeOf(array $node, ?string $alias): ?string
    {
        return match ($node[0]) {
            'val' => match (true) {
                is_bool($node[1])  => 'boolean',
                is_int($node[1])   => 'integer',
                is_float($node[1]) => 'double',
                default            => null,
            },
            'res'   => $alias === null ? null : $this->columnType($alias, $node[1], $node[2]),
            'attr'  => $node[1] === 'env' ? (Vocabulary::ENVIRONMENT_ATTRIBUTES[$node[2]][1] ?? (in_array($node[2], ['hour', 'weekday'], true) ? 'integer' : null)) : null,
            'agg'   => $node[1] === 'count' ? 'integer' : null,
            'math'  => 'double',
            'str'   => 'string',
            'call'  => ['now' => 'dateTime', 'today' => 'date'][$node[1]] ?? null,
            default => null,
        };
    }

    private function typeOfList(array $node): ?string
    {
        $first = $node[0] === 'val' && is_array($node[1]) ? ($node[1][0] ?? null) : null;

        return $first === null ? null : $this->typeOf(['val', $first], null);
    }

    /**
     * Finer than ResourceRegistry::columnType(), which only needs to tell numbers from text.
     * XACML wants to know an integer from a double and a date from a moment.
     */
    private function columnType(string $alias, array $chain, string $column): ?string
    {
        try {
            $class = $this->resources->model($alias);
            foreach ($chain as $name) {
                $class = $this->resources->relation($class, $name)->getRelated()::class;
            }

            $model = new $class;
            $key   = $model->getConnection()->getName().'.'.$model->getTable();

            if (! isset($this->columns[$key])) {
                $this->columns[$key] = [];
                foreach ($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()) as $definition) {
                    $this->columns[$key][$definition['name']] = self::xacmlType(strtolower((string) $definition['type_name']), strtolower((string) $definition['type']));
                }
            }

            return $this->columns[$key][$column] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function xacmlType(string $name, string $full): ?string
    {
        return match (true) {
            $full === 'tinyint(1)', in_array($name, ['bool', 'boolean', 'bit'], true)                                          => 'boolean',
            (bool) preg_match('/^((tiny|small|medium|big)?int(eger)?|int[248]|(small|big)?serial)( unsigned)?$/', $name)       => 'integer',
            (bool) preg_match('/^(numeric|decimal|dec|float[48]?|double( precision)?|real|number|money)( unsigned)?$/', $name) => 'double',
            $name === 'date'                                                                                                   => 'date',
            str_starts_with($name, 'timestamp') || str_starts_with($name, 'datetime')                                          => 'dateTime',
            (bool) preg_match('/char|text|string|^enum$/', $name)                                                              => 'string',
            default                                                                                                            => null,
        };
    }

    private function attributeId(string $path): string
    {
        $own = array_search($path, $this->attributes, true);
        if ($own !== false) {
            return (string) $own;
        }

        [$root, $rest] = explode('.', $path, 2);

        return Vocabulary::OWN.match ($root) {
            'user'  => 'subject:'.$rest,
            'env'   => 'environment:'.$rest,
            default => 'resource:'.$root.':'.$rest,
        };
    }

    private function apply(DOMDocument $doc, string $function, DOMElement ...$arguments): DOMElement
    {
        $apply = $doc->createElementNS(Vocabulary::NS, 'Apply');
        $apply->setAttribute('FunctionId', $function);
        foreach ($arguments as $argument) {
            $apply->appendChild($argument);
        }

        return $apply;
    }

    private function one(DOMDocument $doc, string $type, DOMElement $bag): DOMElement
    {
        return $this->apply($doc, Vocabulary::FN.$type.'-one-and-only', $bag);
    }

    /**
     * MustBePresent stays false. With true a missing attribute fails the Target of a rule as
     * well, and a record with NULL in one column would make every policy that mentions the
     * column Indeterminate, not only the comparison that reads it.
     */
    private function designator(DOMDocument $doc, string $category, string $id, string $type): DOMElement
    {
        $designator = $doc->createElementNS(Vocabulary::NS, 'AttributeDesignator');
        $designator->setAttribute('Category', $category);
        $designator->setAttribute('AttributeId', $id);
        $designator->setAttribute('DataType', Vocabulary::XS.$type);
        $designator->setAttribute('MustBePresent', 'false');

        return $designator;
    }

    public function value(DOMDocument $doc, string $type, mixed $value): DOMElement
    {
        $text = match ($type) {
            'boolean'  => is_bool($value) || in_array($value, [0, 1, '0', '1'], true) ? ($value ? 'true' : 'false') : null,
            'integer'  => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value)) ? (string) $value : null,
            'double'   => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? self::double((float) $value) : null,
            'dateTime' => self::moment($value)?->format('Y-m-d\TH:i:s'),
            'date'     => self::moment($value)?->format('Y-m-d'),
            'time'     => self::moment($value)?->format('H:i:s'),
            default    => is_scalar($value) ? (string) (is_bool($value) ? (int) $value : $value) : null,
        };

        if ($text === null) {
            throw new UntranslatableConditionException('value '.json_encode($value).' is not a '.$type);
        }

        $element = $doc->createElementNS(Vocabulary::NS, 'AttributeValue');
        $element->setAttribute('DataType', Vocabulary::XS.$type);
        $element->appendChild($doc->createTextNode($text));

        return $element;
    }

    /** xs:double has no "1.0E+25" with a plus and wants a point in "100". */
    private static function double(float $value): string
    {
        $text = (string) $value;

        return is_finite($value) ? (preg_match('/^-?\d+$/', $text) ? $text.'.0' : str_replace('E+', 'E', $text)) : throw new UntranslatableConditionException('a number that is not finite');
    }

    private static function moment(mixed $value): ?DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------ XML to text

    private function text(DOMElement $e, array $variables): string
    {
        switch ($e->localName) {
            case 'Condition':
            case 'VariableDefinition':
                return $this->text(self::children($e)[0] ?? throw new UntranslatableConditionException('an empty '.$e->localName), $variables);

            case 'AttributeValue':
                return $this->literal($e);

            case 'AttributeDesignator':
                return $this->path($e);

            case 'VariableReference':
                $id = $e->getAttribute('VariableId');

                return '('.$this->text($variables[$id] ?? throw new UntranslatableConditionException('VariableReference to unknown "'.$id.'"'), $variables).')';

            case 'Apply':
                $arguments = self::children($e);
                $idiom     = $this->idiom($e, $arguments);
                if ($idiom !== null) {
                    return $idiom;
                }

                return $this->call(Vocabulary::local($e->getAttribute('FunctionId')), array_map(fn (DOMElement $a) => $this->text($a, $variables), $arguments), $arguments, $e->getAttribute('FunctionId'));
        }

        throw new UntranslatableConditionException('element <'.$e->localName.'> (AttributeSelector and Function need an XACML engine)');
    }

    /**
     * @param list<string>     $args Arguments already converted to text.
     * @param list<DOMElement> $raw  The same arguments as elements, for the few patterns that look inside.
     */
    private function call(string $function, array $args, array $raw, string $urn = ''): string
    {
        if ($urn === Vocabulary::FN_DSL) {
            return '('.(string) $raw[0]->textContent.')';
        }

        if ($function === 'and' || $function === 'or') {
            return $args === [] ? ($function === 'and' ? 'true' : 'false') : '('.implode($function === 'and' ? ' && ' : ' || ', $args).')';
        }

        if ($function === 'not') {
            return $this->negation($args[0], $raw[0]);
        }

        if (! preg_match('/^(string|boolean|integer|double|date|time|dateTime|anyURI)-(.+)$/', $function, $m)) {
            throw new UntranslatableConditionException('function '.($urn ?: $function));
        }
        [, $type, $name] = $m;

        $comparison = array_search($name, Vocabulary::COMPARISONS, true);
        if ($comparison !== false) {
            return $this->compared($comparison, $args, $raw);
        }

        $arithmetic = array_search($name, Vocabulary::ARITHMETIC, true);
        if ($arithmetic !== false && in_array($type, ['integer', 'double'], true)) {
            // integer-divide drops the remainder, "/" of the package does not.
            if ($name === 'divide' && $type === 'integer') {
                throw new UntranslatableConditionException('integer-divide drops the remainder, which the package has no operation for');
            }

            return '('.implode(' '.$arithmetic.' ', $args).')';
        }

        return match (true) {
            $name === 'one-and-only', $name === 'from-string', $function === 'integer-to-double' => $args[0],
            $name === 'bag-size' && $type === 'anyURI'                                           => 'count('.$args[0].')',
            $name === 'is-in'                                                                    => '('.$args[0].' in '.$args[1].')',
            $name === 'bag'                                                                      => '['.implode(', ', $args).']',
            $function === 'string-normalize-to-lower-case'                                       => 'lower('.$args[0].')',
            in_array($function, Vocabulary::TEXT, true)                                          => array_search($function, Vocabulary::TEXT, true).'('.$args[1].', '.$args[0].')',
            default                                                                              => throw new UntranslatableConditionException('function '.($urn ?: $function)),
        };
    }

    private function compared(string $op, array $args, array $raw): string
    {
        return '('.$args[0].' '.$op.' '.$args[1].')';
    }

    /**
     * Two things the package says in a word and XACML spells out. They are recognised before the
     * arguments are converted, because taken apart they do not convert: the author attribute is
     * no column, and the size of a bag is no count when the bag is one attribute.
     *
     * @param list<DOMElement> $arguments
     */
    private function idiom(DOMElement $apply, array $arguments): ?string
    {
        if (! str_ends_with($apply->getAttribute('FunctionId'), '-equal') || count($arguments) !== 2) {
            return null;
        }

        $ids = array_map(static fn (DOMElement $a) => self::designatorId($a), $arguments);
        if (in_array(Vocabulary::RESOURCE_AUTHOR, $ids, true) && in_array(Vocabulary::SUBJECT_ID, $ids, true)) {
            return 'isAuthor()';
        }

        // integer-equal(type-bag-size(attribute), 0) is how "attribute is null" travels.
        foreach ([[0, 1], [1, 0]] as [$bag, $zero]) {
            if (self::isApply($arguments[$bag], '-bag-size') && ! self::isApply($arguments[$bag], 'anyURI-bag-size') && trim((string) $arguments[$zero]->textContent) === '0') {
                return '('.$this->path(self::children($arguments[$bag])[0]).' == null)';
            }
        }

        return null;
    }

    /**
     * XACML has no "not equal", "not in" and "is not null", it negates. Turning the three back
     * keeps a condition readable after a round trip; everything else stays a negation.
     */
    private function negation(string $inner, DOMElement $raw): string
    {
        if (preg_match('/^\((.+) == null\)$/s', $inner, $m) && self::isApply($raw, 'integer-equal')) {
            return '('.$m[1].' != null)';
        }
        if (preg_match('/^\(count\((.+)\) == 0\)$/s', $inner, $m)) {
            return 'exists('.$m[1].')';
        }
        if (self::isApply($raw, '-equal') && $inner !== 'isAuthor()') {
            return preg_replace('/ == /', ' != ', $inner, 1);
        }
        if (self::isApply($raw, '-is-in')) {
            return preg_replace('/ in /', ' not in ', $inner, 1);
        }

        return '!('.$inner.')';
    }

    private function literal(DOMElement $value): string
    {
        $text = trim((string) $value->textContent);

        return match (Vocabulary::local(str_replace('#', ':', $value->getAttribute('DataType')))) {
            'integer'                          => preg_match('/^[+-]?\d+$/', $text) ? (string) (int) $text : throw new UntranslatableConditionException('"'.$text.'" is not an integer'),
            'double'                           => is_numeric($text) ? (string) (float) $text : throw new UntranslatableConditionException('"'.$text.'" is not a double'),
            'boolean'                          => in_array($text, ['true', '1'], true) ? 'true' : 'false',
            'dateTime'                         => "'".(self::moment($text)?->format('Y-m-d H:i:s') ?? throw new UntranslatableConditionException('"'.$text.'" is not a dateTime'))."'",
            'string', 'date', 'time', 'anyURI' => "'".addcslashes($text, "'\\")."'",
            default                            => throw new UntranslatableConditionException('data type '.$value->getAttribute('DataType')),
        };
    }

    private function path(DOMElement $designator): string
    {
        if ($designator->localName !== 'AttributeDesignator') {
            throw new UntranslatableConditionException('<'.$designator->localName.'> where an attribute is expected');
        }

        $id = $designator->getAttribute('AttributeId');

        if (isset($this->attributes[$id])) {
            return $this->attributes[$id];
        }

        foreach (Vocabulary::ENVIRONMENT_ATTRIBUTES as $name => [$urn]) {
            if ($urn === $id) {
                return 'env.'.$name;
            }
        }

        return match (true) {
            $id === Vocabulary::SUBJECT_ID                                                           => 'user.id',
            $id === Vocabulary::ACTION_ID                                                            => 'action.id',
            str_starts_with($id, Vocabulary::OWN.'subject:')                                         => 'user.'.substr($id, strlen(Vocabulary::OWN.'subject:')),
            str_starts_with($id, Vocabulary::OWN.'environment:')                                     => 'env.'.substr($id, strlen(Vocabulary::OWN.'environment:')),
            str_starts_with($id, Vocabulary::OWN.'resource:') && $id !== Vocabulary::RESOURCE_AUTHOR => str_replace(':', '.', substr($id, strlen(Vocabulary::OWN.'resource:'))),
            default                                                                                  => throw new UntranslatableConditionException('attribute "'.$id.'" is unknown; map it to a path in config access.xacml.attributes'),
        };
    }

    private static function designatorId(DOMElement $e): ?string
    {
        while ($e->localName === 'Apply' && count(self::children($e)) === 1) {
            $e = self::children($e)[0];
        }

        return $e->localName === 'AttributeDesignator' ? $e->getAttribute('AttributeId') : null;
    }

    private static function isApply(DOMElement $e, string $ending): bool
    {
        return $e->localName === 'Apply' && str_ends_with($e->getAttribute('FunctionId'), $ending);
    }

    /**
     * @return list<DOMElement>
     */
    public static function children(DOMElement $e, ?string $name = null): array
    {
        $found = [];
        foreach ($e->childNodes as $child) {
            if ($child instanceof DOMElement && ($name === null || $child->localName === $name)) {
                $found[] = $child;
            }
        }

        return $found;
    }

    public static function child(DOMElement $e, string $name): ?DOMElement
    {
        return self::children($e, $name)[0] ?? null;
    }
}
