<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Xacml;

use SplFileInfo;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Internal\Xacml\Exporter;
use Wnikk\LaravelAccessRules\Internal\Xacml\Importer;
use ZipArchive;

/**
 * The one door to the XACML module: export to anywhere, check and import from anywhere.
 *
 * A console command writes files, a controller sends a download to a browser, another controller
 * receives an upload, a queued job reads from a disk of the application. They differ in where
 * bytes come from and go to, and in nothing else. This class takes that difference away, so each
 * of them is three lines. Exporter and Importer stay free of files: one writes into a stream,
 * the other reads text.
 *
 *     // a download
 *     return response()->streamDownload(fn () => $xacml->exportArchive('php://output'), 'access-rules.zip');
 *
 *     // an upload: show the plan, then import
 *     $plan = $xacml->check($request->file('policy'));
 *     $done = $xacml->import($request->file('policy'), options: ['replace' => true]);
 *
 * An export is two files, the policy and the manifest, and a person wants to download and
 * upload one. The archive is a zip of both. It needs the PHP extension "zip"; without it the
 * two parts remain available one by one.
 */
final class Xacml
{
    public const POLICY = 'policy.xml';

    public const MANIFEST = 'manifest.json';

    private const ZIP = "PK\x03\x04";

    public function __construct(
        private Exporter $exporter,
        private Importer $importer,
    ) {}

    /**
     * Written as it is produced, one owner at a time, so the size of the export does not
     * become the size of the memory.
     *
     * @param  resource|string $target An open stream, or something fopen() can write to: a path, "php://output".
     * @return list<string>    Warnings of the export. exportManifest() takes them, so a download carries them too.
     */
    public function exportPolicy($target): array
    {
        return $this->into($target, fn ($stream) => $this->exporter->policy($stream));
    }

    /**
     * @param resource|string $target
     * @param list<string>    $warnings What exportPolicy() returned.
     */
    public function exportManifest($target, array $warnings = []): void
    {
        $this->into($target, fn ($stream) => $this->exporter->manifest($stream, $warnings));
    }

    /**
     * @return list<string> Warnings of the export.
     */
    public function exportDirectory(string $directory): array
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new AccessRulesException('Cannot create the directory "'.$directory.'".');
        }

        $warnings = $this->exportPolicy($directory.'/'.self::POLICY);
        $this->exportManifest($directory.'/'.self::MANIFEST, $warnings);

        return $warnings;
    }

    /**
     * Both parts as one zip. The archive is assembled in a temporary file and then copied to the
     * target: a zip ends with a directory of what it holds and cannot be written front to back.
     * The policy still streams into that file, so memory stays flat; the disk pays instead.
     *
     * @param  resource|string $target
     * @return list<string>    Warnings of the export.
     */
    public function exportArchive($target): array
    {
        self::needsZip();

        $work = (string) tempnam(sys_get_temp_dir(), 'acr-xacml-');

        try {
            $warnings = $this->exportPolicy($work.'.xml');
            $this->exportManifest($work.'.json', $warnings);

            $zip = new ZipArchive;
            if ($zip->open($work, ZipArchive::OVERWRITE) !== true) {
                throw new AccessRulesException('Cannot create a temporary archive in "'.sys_get_temp_dir().'".');
            }
            $zip->addFile($work.'.xml', self::POLICY);
            $zip->addFile($work.'.json', self::MANIFEST);
            $zip->close();

            $this->into($target, function ($stream) use ($work) {
                $archive = fopen($work, 'r');
                stream_copy_to_stream($archive, $stream);
                fclose($archive);
            });

            return $warnings;
        } finally {
            foreach ([$work, $work.'.xml', $work.'.json'] as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * What the document would change, as a plan, without changing anything. See Importer::check().
     *
     * @param mixed $source   An uploaded file or any SplFileInfo, a path to policy.xml or to an archive, a directory of an export, an open stream, or the XML itself.
     * @param mixed $manifest The same kinds of source for manifest.json, or the decoded array. Null looks inside the archive and next to the policy.
     */
    public function check(mixed $source, mixed $manifest = null, array $options = []): array
    {
        [$xml, $found] = $this->read($source);

        return $this->importer->check($xml, $this->manifest($manifest) ?? $found, $options);
    }

    /**
     * Executes the plan of check(). See Importer::import() for what is written and when nothing is.
     */
    public function import(mixed $source, mixed $manifest = null, array $options = []): array
    {
        [$xml, $found] = $this->read($source);

        return $this->importer->import($xml, $this->manifest($manifest) ?? $found, $options);
    }

    /**
     * Files of an archive are read by name and never unpacked, so a crafted archive has no path
     * to write outside of a directory: there is no directory.
     *
     * @return array{0:string, 1:?array} The policy and the manifest that came with it.
     */
    private function read(mixed $source): array
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

        // XML text starts with "<", a zip with its four signature bytes. Everything else is a path.
        if (str_starts_with(ltrim($source), '<')) {
            return [$source, null];
        }
        if (str_starts_with($source, self::ZIP)) {
            return $this->unzip($source, false);
        }

        if (is_dir($source)) {
            $source = rtrim($source, '/').'/'.self::POLICY;
        }
        if (! is_file($source) || ! is_readable($source)) {
            throw new AccessRulesException('Cannot read "'.$source.'".');
        }

        if (file_get_contents($source, false, null, 0, 4) === self::ZIP) {
            return $this->unzip($source, true);
        }

        $beside = dirname($source).'/'.self::MANIFEST;

        return [(string) file_get_contents($source), is_file($beside) ? $this->manifest($beside) : null];
    }

    private function unzip(string $source, bool $isPath): array
    {
        self::needsZip();

        $work = null;
        if (! $isPath) {
            $work = (string) tempnam(sys_get_temp_dir(), 'acr-xacml-');
            file_put_contents($work, $source);
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($work ?? $source, ZipArchive::RDONLY) !== true) {
                throw new AccessRulesException('The archive cannot be opened.');
            }

            $policy   = $zip->getFromName(self::POLICY);
            $manifest = $zip->getFromName(self::MANIFEST);
            $zip->close();

            if ($policy === false) {
                throw new AccessRulesException('The archive has no "'.self::POLICY.'".');
            }

            return [$policy, $manifest === false ? null : $this->manifest($manifest)];
        } finally {
            if ($work !== null) {
                @unlink($work);
            }
        }
    }

    private function manifest(mixed $manifest): ?array
    {
        if ($manifest === null || is_array($manifest)) {
            return $manifest;
        }
        if (is_resource($manifest)) {
            $manifest = (string) stream_get_contents($manifest);
        }
        if ($manifest instanceof SplFileInfo) {
            $manifest = $manifest->getPathname();
        }
        if (is_string($manifest) && ! str_starts_with(ltrim($manifest), '{')) {
            $manifest = is_file($manifest) ? (string) file_get_contents($manifest) : throw new AccessRulesException('Cannot read the manifest "'.$manifest.'".');
        }

        $decoded = json_decode((string) $manifest, true);

        return is_array($decoded) ? $decoded : throw new AccessRulesException('The manifest is not valid JSON.');
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

    private static function needsZip(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new AccessRulesException('An archive needs the PHP extension "zip". The policy and the manifest are available as two files without it.');
        }
    }
}
