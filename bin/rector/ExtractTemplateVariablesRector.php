<?php

declare(strict_types=1);

namespace Friendica\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class ExtractTemplateVariablesRector extends AbstractRector
{
    /** @var array<string, array<string, array<array{file: string, line: int}>>> */
    private static array $extractedVariables = [];
    private static bool $shutdownRegistered = false;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Extract template variables from Renderer::replaceMacros() calls with context', [
            new CodeSample('', ''),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [StaticCall::class];
    }

    public function refactor(Node $node)
    {
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'saveVariables']);
            self::$shutdownRegistered = true;
        }

        if (!$node instanceof StaticCall) {
            return null;
        }

        if (!$node->class instanceof \PhpParser\Node\Name) {
            return null;
        }

        $className = $this->getName($node->class);
        if (!$node->name instanceof \PhpParser\Node\Identifier) {
            return null;
        }
        $methodName = $this->getName($node->name);

        if ($className !== 'Friendica\Core\Renderer' && $className !== 'Renderer') {
            return null;
        }

        if ($methodName !== 'replaceMacros') {
            return null;
        }

        $args = $node->getArgs();
        if (count($args) < 2) {
            return null;
        }

        $templateArg = $args[0]->value;
        $varsArg = $args[1]->value;

        // Try to get template name
        $templateName = '*(dynamic or unknown template)*';
        if ($templateArg instanceof \PhpParser\Node\Scalar\String_) {
            $templateName = $templateArg->value;
        } elseif ($templateArg instanceof StaticCall) {
            $innerCallName = $this->getName($templateArg->name);
            if ($innerCallName === 'getMarkupTemplate') {
                $innerArgs = $templateArg->getArgs();
                if (isset($innerArgs[0]) && $innerArgs[0]->value instanceof \PhpParser\Node\Scalar\String_) {
                    $templateName = $innerArgs[0]->value->value;
                }
            }
        }

        // Try to get variables
        $foundNew = false;
        if ($varsArg instanceof \PhpParser\Node\Expr\Array_) {
            foreach ($varsArg->items as $item) {
                if ($item !== null && $item->key instanceof \PhpParser\Node\Scalar\String_) {
                    $varName = $item->key->value;
                    if (!isset(self::$extractedVariables[$varName])) {
                        self::$extractedVariables[$varName] = [];
                    }
                    if (!isset(self::$extractedVariables[$varName][$templateName])) {
                        self::$extractedVariables[$varName][$templateName] = [];
                    }

                    $file = $this->file ? $this->file->getFilePath() : 'unknown file';

                    // Normalize relative paths
                    $cwd = getcwd() . DIRECTORY_SEPARATOR;
                    if (str_starts_with($file, $cwd)) {
                        $file = substr($file, strlen($cwd));
                    }
                    $line = $node->getStartLine();

                    $context = [
                        'file' => $file,
                        'line' => $line
                    ];

                    // Check if we already have this context to avoid duplicates
                    $exists = false;
                    foreach (self::$extractedVariables[$varName][$templateName] as $existingContext) {
                        if ($existingContext['file'] === $context['file'] && $existingContext['line'] === $context['line']) {
                            $exists = true;
                            break;
                        }
                    }

                    if (!$exists) {
                        self::$extractedVariables[$varName][$templateName][] = $context;
                        $foundNew = true;
                    }
                }
            }
        }

        if ($foundNew) {
            self::saveVariables();
        }

        return null;
    }

    public static function saveVariables()
    {
        if (empty(self::$extractedVariables)) {
            return;
        }

        $filePath = __DIR__ . '/../../doc/TemplateVariablesContext.example.md';

        // Ensure doc directory exists
        if (!is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0777, true);
        }

        $existingVars = [];
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);

            // Re-parse existing markdown for context.
            // This regex tries to find the variables, their templates, and the usage files.
            // Format:
            // ### `$varName`
            //
            // #### `templateName`
            // - Used in `file` on line X

            if (preg_match_all('/### `(.*?)`\n\n(.*?)(?=\n### |\z)/s', $content, $matches)) {
                foreach ($matches[1] as $index => $varName) {
                    $existingVars[$varName] = [];
                    $varContent = $matches[2][$index];

                    if (preg_match_all('/#### (.*?)\n(.*?)(?=\n#### |\z)/s', $varContent, $templateMatches)) {
                        foreach ($templateMatches[1] as $tIndex => $tName) {
                            $tNameClean = trim($tName, '`*');
                            if ($tNameClean === '(dynamic or unknown template)' || strpos($tNameClean, 'dynamic') !== false) {
                                $tNameClean = '*(dynamic or unknown template)*';
                            }

                            $existingVars[$varName][$tNameClean] = [];
                            $linesContent = $templateMatches[2][$tIndex];

                            if (preg_match_all('/- Used in `(.*?)` on line (\d+)/', $linesContent, $lineMatches)) {
                                foreach ($lineMatches[1] as $lIndex => $file) {
                                    $line = (int)$lineMatches[2][$lIndex];
                                    $existingVars[$varName][$tNameClean][] = [
                                        'file' => $file,
                                        'line' => $line
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Merge new variables with existing ones
        foreach (self::$extractedVariables as $varName => $templates) {
            if (!isset($existingVars[$varName])) {
                $existingVars[$varName] = [];
            }
            foreach ($templates as $template => $contexts) {
                $cleanTemplate = trim($template, '`*');
                if ($cleanTemplate === 'unknown' || strpos($cleanTemplate, 'dynamic') !== false) {
                    $cleanTemplate = '*(dynamic or unknown template)*';
                }

                if (!isset($existingVars[$varName][$cleanTemplate])) {
                    $existingVars[$varName][$cleanTemplate] = [];
                }

                foreach ($contexts as $context) {
                    $exists = false;
                    foreach ($existingVars[$varName][$cleanTemplate] as $existingContext) {
                        if ($existingContext['file'] === $context['file'] && $existingContext['line'] === $context['line']) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $existingVars[$varName][$cleanTemplate][] = $context;
                    }
                }
            }
        }

        // Generate markdown
        $newContent = "# Template Variables Context Example\n\nThis file documents all variables used in `Renderer::replaceMacros()` and the templates they are used in, including their source code usage locations.\n\n";

        ksort($existingVars);
        foreach ($existingVars as $varName => $templates) {
            $newContent .= "### `$varName`\n\n";
            ksort($templates);
            foreach ($templates as $template => $contexts) {
                if ($template === '*(dynamic or unknown template)*') {
                    $cleanTemplate = $template;
                } else {
                    $cleanTemplate = "`$template`";
                }
                $newContent .= "#### $cleanTemplate\n";

                // Sort contexts by file and then by line
                usort($contexts, function($a, $b) {
                    if ($a['file'] === $b['file']) {
                        return $a['line'] <=> $b['line'];
                    }
                    return $a['file'] <=> $b['file'];
                });

                foreach ($contexts as $context) {
                    $newContent .= "- Used in `{$context['file']}` on line {$context['line']}\n";
                }
                $newContent .= "\n";
            }
        }

        file_put_contents($filePath, $newContent);
    }
}
