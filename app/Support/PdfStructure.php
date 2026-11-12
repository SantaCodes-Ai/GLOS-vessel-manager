<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class PdfStructure
{
    public function valid(UploadedFile $file): bool
    {
        try {
            $path = $file->getRealPath();
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                return false;
            }
            $header = file_get_contents($path, false, null, 0, 5);
            $tail = file_get_contents($path, false, null, -min(1024, filesize($path)));
            if ($header !== '%PDF-' || ! is_string($tail) || ! preg_match('/%%EOF[\x00\x09\x0A\x0C\x0D\x20]*\z/', $tail)) {
                return false;
            }
            $configured = config('pdf.qpdf_binary');
            $binary = is_string($configured) && $configured !== ''
                ? (is_file($configured) && is_executable($configured)
                    ? $configured
                    : (new ExecutableFinder)->find($configured, null, ['/opt/homebrew/bin', '/usr/local/bin']))
                : null;
            if ($binary === null) {
                Log::error('PDF validation unavailable: qpdf executable not found.');

                return false;
            }
            // No shell, client-supplied filename, output file, repair or rewrite.
            $check = new Process([$binary, '--suppress-recovery', '--check', $path]);
            $check->setTimeout(10)->disableOutput();
            if ($check->run() !== 0) {
                return false;
            }

            $pages = new Process([$binary, '--suppress-recovery', '--show-npages', $path]);
            $pages->setTimeout(5)->disableOutput();
            $output = '';
            $exit = $pages->run(function (string $type, string $buffer) use (&$output): void {
                if ($type === Process::OUT && strlen($output) < 64) {
                    $output .= substr($buffer, 0, 64 - strlen($output));
                }
            });

            return $exit === 0 && strlen($output) < 64 && preg_match('/\A[1-9][0-9]*\s*\z/', $output) === 1;
        } catch (Throwable $exception) {
            // Do not include parser output, local paths, filenames or document data.
            Log::error('PDF structure validation could not complete.', ['exception_type' => $exception::class]);

            return false;
        }
    }
}
