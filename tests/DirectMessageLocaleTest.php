<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class DirectMessageLocaleTest extends TestCase
{
    public function testEnglishSidebarLabelIsDirectMessages(): void
    {
        $locale = $this->loadEnglishLocale();

        $this->assertSame(
            'Direct Messages',
            $locale['neoncube-private-messages']['forum']['dropdown']['tooltip']
        );
    }

    public function testEnglishProfileActionLabelIsDirectMessage(): void
    {
        $locale = $this->loadEnglishLocale();

        $this->assertSame(
            'Direct Message',
            $locale['neoncube-private-messages']['forum']['profile']['direct_message']
        );
    }

    private function loadEnglishLocale(): array
    {
        $path = dirname(__DIR__) . '/resources/locale/en.yml';
        $this->assertFileExists($path);

        if (class_exists(Yaml::class)) {
            return Yaml::parseFile($path);
        }

        // Minimal parser for this package's flat locale shape when symfony/yaml is absent.
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $data = [];
        $stack = [&$data];
        $indents = [-1];

        foreach ($lines as $line) {
            if ($line === '' || preg_match('/^\s*#/', $line)) {
                continue;
            }
            if (!preg_match('/^( *)([^:]+):\s*(.*)$/', $line, $m)) {
                continue;
            }
            $indent = strlen($m[1]);
            $key = $m[2];
            $value = $m[3];

            while (count($indents) > 1 && $indent <= $indents[count($indents) - 1]) {
                array_pop($indents);
                array_pop($stack);
            }

            $parent = &$stack[count($stack) - 1];
            if ($value === '' || $value === '>-' || $value === '|') {
                $parent[$key] = [];
                $stack[] = &$parent[$key];
                $indents[] = $indent;
            } else {
                if (
                    (str_starts_with($value, "'") && str_ends_with($value, "'")) ||
                    (str_starts_with($value, '"') && str_ends_with($value, '"'))
                ) {
                    $value = substr($value, 1, -1);
                }
                $parent[$key] = $value;
            }
        }

        return $data;
    }
}
