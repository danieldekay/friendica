<?php

declare(strict_types=1);

namespace Friendica\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class ExtractTemplateVariablesRector extends AbstractRector
{
    /** @var array<string, array{type: string, description: string, contexts: array<array{file: string, line: int, template: string}>}> */
    private static array $extractedVariables = [];
    private static bool $shutdownRegistered = false;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Extract template variables from Renderer::replaceMacros() calls with context, type, and description', [
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
        } elseif ($templateArg instanceof Variable) {
            // Very common pattern: $tpl = Renderer::getMarkupTemplate('...');
            // In a real Rector rule, we would use node scopes to find the previous assignment.
            // For now, if we can't find it easily, we mark it as dynamic.
            // A more advanced rule could traverse back in the block to find the assignment to this variable.
        }

        // Try to get variables
        $foundNew = false;
        if ($varsArg instanceof \PhpParser\Node\Expr\Array_) {
            foreach ($varsArg->items as $item) {
                if ($item !== null && $item->key instanceof \PhpParser\Node\Scalar\String_) {
                    $varName = $item->key->value;
                    $varValue = $item->value;

                    if (!isset(self::$extractedVariables[$varName])) {
                        self::$extractedVariables[$varName] = [
                            'type' => 'Mixed',
                            'description' => '',
                            'contexts' => []
                        ];
                    }

                    // Infer type
                    $inferredType = 'Mixed';
                    if ($varValue instanceof \PhpParser\Node\Scalar\String_) {
                        $inferredType = 'String';
                    } elseif ($varValue instanceof \PhpParser\Node\Scalar\LNumber) {
                        $inferredType = 'Integer';
                    } elseif ($varValue instanceof \PhpParser\Node\Expr\Array_) {
                        $inferredType = 'Array';
                    } elseif ($varValue instanceof \PhpParser\Node\Expr\ConstFetch) {
                        if ($varValue->name !== null && $varValue->name instanceof \PhpParser\Node\Name) {
                            $constName = strtolower($this->getName($varValue->name));
                            if ($constName === 'true' || $constName === 'false') {
                                $inferredType = 'Boolean';
                            }
                        }
                    } elseif ($varValue instanceof \PhpParser\Node\Expr\MethodCall) {
                         // Often it returns strings or mixed
                    }

                    // Update type if we have a better one than 'Mixed'
                    if (self::$extractedVariables[$varName]['type'] === 'Mixed' && $inferredType !== 'Mixed') {
                        self::$extractedVariables[$varName]['type'] = $inferredType;
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
                        'line' => $line,
                        'template' => $templateName
                    ];

                    // Check if we already have this context to avoid duplicates
                    $exists = false;
                    foreach (self::$extractedVariables[$varName]['contexts'] as $existingContext) {
                        if ($existingContext['file'] === $context['file'] && $existingContext['line'] === $context['line'] && $existingContext['template'] === $context['template']) {
                            $exists = true;
                            break;
                        }
                    }

                    if (!$exists) {
                        self::$extractedVariables[$varName]['contexts'][] = $context;
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

        $existingData = [];
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);

            if (preg_match_all('/### `(.*?)`\n\n- \*\*Type:\*\* (.*?)\n- \*\*Context:\*\* (.*?)\n\n#### Usages\n(.*?)(?=\n### |\z)/s', $content, $matches)) {
                foreach ($matches[1] as $index => $varName) {
                    $type = $matches[2][$index];

                    $existingData[$varName] = [
                        'type' => $type,
                        'description' => '',
                        'contexts' => []
                    ];

                    $usages = $matches[4][$index];

                    if (preg_match_all('/- Used in (.*?) from `(.*?)` on line (\d+)/', $usages, $usageMatches)) {
                        foreach ($usageMatches[1] as $uIndex => $tName) {
                            $file = $usageMatches[2][$uIndex];
                            $line = (int)$usageMatches[3][$uIndex];
                            $tNameClean = trim($tName, '`*');
                            if ($tNameClean === '(dynamic or unknown template)' || strpos($tNameClean, 'dynamic') !== false) {
                                $tNameClean = '*(dynamic or unknown template)*';
                            }

                            $existingData[$varName]['contexts'][] = [
                                'file' => $file,
                                'line' => $line,
                                'template' => $tNameClean
                            ];
                        }
                    }
                }
            }
        }

        // Merge memory data with existing data
        foreach (self::$extractedVariables as $varName => $info) {
            if (!isset($existingData[$varName])) {
                $existingData[$varName] = [
                    'type' => 'Mixed',
                    'description' => '',
                    'contexts' => []
                ];
            }

            if ($existingData[$varName]['type'] === 'Mixed' && $info['type'] !== 'Mixed') {
                $existingData[$varName]['type'] = $info['type'];
            }

            foreach ($info['contexts'] as $ctx) {
                $exists = false;
                foreach ($existingData[$varName]['contexts'] as $eCtx) {
                    if ($eCtx['file'] === $ctx['file'] && $eCtx['line'] === $ctx['line'] && $eCtx['template'] === $ctx['template']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $existingData[$varName]['contexts'][] = $ctx;
                }
            }
        }

        ksort($existingData);

        // Generate markdown
        $newContent = "---
Note: This document was initially generated by Jules, an AI assistant, based on code analysis of the Friendica project. It has been reviewed and may be further updated by the Friendica community.
---

# Friendica Template Variables

## Introduction

Friendica utilizes the Smarty templating engine for rendering its user interface. Templates are typically `.tpl` files located in the `view/templates/` directory and within specific theme directories (e.g., `view/theme/frio/tpl/`).

Variables are passed from PHP controller logic to these Smarty templates. This is primarily handled by the `Friendica\Core\Renderer::replaceMacros()` method, which takes a template string and an associative array of variables to substitute.

In Smarty templates (`.tpl` files), variables are accessed using Smarty's syntax, such as `{\$variable}` for simple variables or `{\$array.key}` for elements within arrays/objects. The PHP code passes an associative array of data to the template engine, and the keys of this array become the top-level template variables.

This document aims to catalog commonly used variables available in Friendica templates.

**Note on Localization**: Many textual variables are localized in PHP using Friendica's internationalization system (e.g., `DI::l10n()->t()`) before being passed to templates. The 'Localized: Yes' note indicates that the string content is subject to translation.

## Extracted Variables

";

        foreach ($existingData as $varName => $info) {
            $newContent .= "### `$varName`\n\n";
            $newContent .= "- **Type:** {$info['type']}\n";

            // Collect unique templates for the context summary
            $templates = [];
            foreach ($info['contexts'] as $ctx) {
                $cleanTpl = trim($ctx['template'], '`*');
                if ($cleanTpl === 'unknown' || strpos($cleanTpl, 'dynamic') !== false) {
                    $cleanTpl = '*(dynamic or unknown template)*';
                } else {
                    $cleanTpl = "`$cleanTpl`";
                }
                if (!in_array($cleanTpl, $templates)) {
                    $templates[] = $cleanTpl;
                }
            }
            sort($templates);
            $templateList = implode(', ', $templates);

            $newContent .= "- **Context:** $templateList\n\n";

            $newContent .= "#### Usages\n";

            // Sort contexts
            usort($info['contexts'], function($a, $b) {
                if ($a['file'] === $b['file']) {
                    return $a['line'] <=> $b['line'];
                }
                return $a['file'] <=> $b['file'];
            });

            foreach ($info['contexts'] as $ctx) {
                $cleanTpl = trim($ctx['template'], '`*');
                if ($cleanTpl === 'unknown' || strpos($cleanTpl, 'dynamic') !== false) {
                    $cleanTpl = '*(dynamic or unknown template)*';
                } else {
                    $cleanTpl = "`$cleanTpl`";
                }
                $newContent .= "- Used in $cleanTpl from `{$ctx['file']}` on line {$ctx['line']}\n";
            }
            $newContent .= "\n";
        }

        file_put_contents($filePath, $newContent);
    }
}
