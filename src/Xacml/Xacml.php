<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Xacml;

use SplFileInfo;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Protected\Xacml\Exporter;
use Wnikk\LaravelAccessRules\Protected\Xacml\Importer;

/**
 * The entry to the XACML module: export to any target, check and import from any source.
 *
 * A console command writes a file, a controller sends a download to a browser, another controller
 * receives an upload, a queued job reads from a disk of the application. They differ in where
 * bytes come from and go to, and in nothing else. This class takes that difference away, so each
 * of them is three lines. Exporter and Importer stay free of files: one writes into a stream,
 * the other reads text.
 *
 *     // a download
 *     return response()->streamDownload(fn () => $xacml->export('php://output'), 'access.xml', ['Content-Type' => 'application/xml']);
 *
 *     // an upload: show the plan, then import
 *     $plan = $xacml->check($request->file('policy'));
 *     $done = $xacml->import($request->file('policy'), ['replace' => true]);
 *
 * An export is one file. What XACML has no place for, titles of rules, names of owners and
 * inheritance, travels inside the document as a policy set no request reaches. Version 3.1 kept
 * that in a second file and offered a zip of both; a download and an upload were then two files
 * or an archive that needed the zip extension.
 */
final class Xacml
{
    public function __construct(
        private Exporter $exporter,
        private Importer $importer,
    ) {}

    /**
     * Written as it is produced, one owner at a time, so the size of the export does not
     * become the size of the memory.
     *
     * @param  resource|string $target An open stream, or something fopen() can write to: a path, "php://output".
     * @return list<string>    Warnings of the export. They are written into the document too, so a download carries them.
     */
    public function export($target): array
    {
        return $this->into($target, fn ($stream) => $this->exporter->document($stream));
    }

    /**
     * What the document would change, as a plan, without changing anything. See Importer::check().
     *
     * @param mixed $source An uploaded file or any SplFileInfo, a path, an open stream, or the XML itself.
     */
    public function check(mixed $source, array $options = []): array
    {
        return $this->importer->check($this->read($source), $options);
    }

    /**
     * Executes the plan of check(). See Importer::import() for what is written and when nothing is.
     */
    public function import(mixed $source, array $options = []): array
    {
        return $this->importer->import($this->read($source), $options);
    }

    private function read(mixed $source): string
    {
        if (is_resource($source)) {
            $source = (string) stream_get_contents($source);
        }
        if ($source instanceof SplFileInfo) {
            $source = $source->getPathname();
        }
        if (! is_string($source) || $source === '') {
            throw new AccessRulesException('An XACML source is a file, a path, a stream or XML text.');
        }

        // XML text starts with "<". Everything else is a path.
        if (str_starts_with(ltrim($source), '<')) {
            return $source;
        }
        if (! is_file($source) || ! is_readable($source)) {
            throw new AccessRulesException('Cannot read "'.$source.'".');
        }

        return (string) file_get_contents($source);
    }

    /**
     * A stream handed in stays open: whoever opened it may have more to write. A stream opened
     * here is closed here.
     */
    private function into($target, callable $write): mixed
    {
        if (is_resource($target)) {
            return $write($target);
        }

        $stream = @fopen((string) $target, 'w');
        if ($stream === false) {
            throw new AccessRulesException('Cannot write to "'.$target.'".');
        }

        try {
            return $write($stream);
        } finally {
            fclose($stream);
        }
    }
}
