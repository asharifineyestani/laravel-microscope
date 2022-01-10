<?php

namespace Imanghafoori\LaravelMicroscope\Psr4;

use Illuminate\Support\Facades\Event;
use Imanghafoori\LaravelMicroscope\Analyzers\ComposerJson;
use Imanghafoori\LaravelMicroscope\CheckNamespaces;
use Imanghafoori\LaravelMicroscope\ErrorReporters\ErrorPrinter;
use Imanghafoori\LaravelMicroscope\FileReaders\FilePath;
use Imanghafoori\LaravelMicroscope\FileSystem\FileSystem;
use Imanghafoori\LaravelMicroscope\LaravelPaths\LaravelPaths;

class ClassRefCorrector
{
    private static function possibleOccurrence($olds)
    {
        $keywords = ['(', '::', ';', '|', ')', "\r\n", "\n", "\r", '$', '{', '?', ','];

        $occurrences = [];
        foreach ($olds as $old) {
            foreach ($keywords as $keyword) {
                $occurrences[] = $old.$keyword;
            }
        }

        return $occurrences;
    }


    public static function fixAllRefs()
    {
        if (CheckNamespaces::$changedNamespaces && ! config('microscope.no_fix')) {
            $olds = \array_keys(CheckNamespaces::$changedNamespaces);
            $news = \array_values(CheckNamespaces::$changedNamespaces);

            ClassRefCorrector::changeReferences($olds, $news);
        }
    }

    static function changeReferences($olds, $news)
    {
        $autoload = ComposerJson::readAutoload();
        $olds = [$olds, self::possibleOccurrence($olds)];

        foreach ($autoload as $psr4Path) {
            $files = FilePath::getAllPhpFiles($psr4Path);
            foreach ($files as $classFilePath) {
                $_path = $classFilePath->getRealPath();
                self::fixAndReport($_path, $olds, $news);
            }
        }

        foreach (LaravelPaths::collectNonPsr4Paths() as $_path) {
            self::fixAndReport($_path, $olds, $news);
        }
    }

    private static function fixAndReport($_path, $olds, $news)
    {
        $lineNumbers = self::fixRefs($_path, $olds, $news);

        foreach ($lineNumbers as $line) {
            self::report($_path, $line);
        }
    }

    private function report($_path, $lineNumber)
    {
        app(ErrorPrinter::class)->simplePendError('', $_path, $lineNumber, 'ns_replacement', 'Namespace replacement:');
    }

    private static function fixRefs($_path, $olds, $news)
    {
        [$olds, $occurrences] = $olds;
        $lines = file($_path);
        $changedLineNums = [];
        foreach ($lines as $lineIndex => $lineContent) {
            if (self::str_contains(\str_replace(' ', '', $lineContent), $occurrences)) {
                $count = 0;
                $lines[$lineIndex] = \str_replace($olds, $news, $lineContent, $count);
                $count && $changedLineNums[] = ($lineIndex + 1);
            } elseif (self::str_contains($lineContent, $olds)) {
                $response = Event::dispatch('microscope.replacing_namespace', [$_path, $lineIndex + 1, $lineContent], true);

                if ($response !== false) {
                    $count = 0;
                    $lines[$lineIndex] = \str_replace($olds, $news, $lineContent, $count);
                    $count && $changedLineNums[] = ($lineIndex + 1);
                }
            }
        }

        // saves the file into disk.
        $changedLineNums && FileSystem::$fileSystem::file_put_contents($_path, \implode('', $lines));

        return $changedLineNums;
    }

    private function str_contains($haystack, $needles)
    {
        foreach ($needles as $needle) {
            if (mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
