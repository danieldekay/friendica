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
    /** @var array<string, array<string>> */
    private static array $extractedVariables = [];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Extract template variables from Renderer::replaceMacros() calls', [
            new CodeSample('', ''),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [StaticCall::class];
    }

    public function refactor(Node $node)
    {
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
        if ($varsArg instanceof \PhpParser\Node\Expr\Array_) {
            foreach ($varsArg->items as $item) {
                if ($item !== null && $item->key instanceof \PhpParser\Node\Scalar\String_) {
                    $varName = $item->key->value;
                    if (!isset(self::$extractedVariables[$varName])) {
                        self::$extractedVariables[$varName] = [];
                    }
                    if (!in_array($templateName, self::$extractedVariables[$varName])) {
                        self::$extractedVariables[$varName][] = $templateName;
                    }
                }
            }
        }

        self::saveVariables();

        return null;
    }

    public static function saveVariables()
    {
        if (empty(self::$extractedVariables)) {
            return;
        }

        $filePath = __DIR__ . '/../../doc/TemplateVariables.md';

        // Ensure doc directory exists
        if (!is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0777, true);
        }

        $existingVars = [];
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            // Parse existing content to merge
            if (preg_match_all('/### `(.*?)`\n\n(.*?)(?=\n###|\z)/s', $content, $matches)) {
                foreach ($matches[1] as $index => $varName) {
                    $templates = explode("\n", trim($matches[2][$index]));
                    $templates = array_map(function($t) {
                        return trim($t, '- *`');
                    }, $templates);

                    // Filter out empty strings
                    $templates = array_filter($templates, function($t) { return $t !== ''; });

                    $existingVars[$varName] = $templates;
                }
            }
        }

        // Merge new variables
        foreach (self::$extractedVariables as $varName => $templates) {
            if (!isset($existingVars[$varName])) {
                $existingVars[$varName] = [];
            }
            foreach ($templates as $template) {
                $cleanTemplate = trim($template, '`*');
                if ($cleanTemplate === 'unknown' || strpos($cleanTemplate, 'dynamic') !== false) {
                    $cleanTemplate = '*(dynamic or unknown template)*';
                }

                if (!in_array($cleanTemplate, $existingVars[$varName])) {
                    $existingVars[$varName][] = $cleanTemplate;
                }
            }
        }

        // Sort alphabetically
        ksort($existingVars);

        // Generate markdown
        $newContent = "# Template Variables\n\nThis file documents all variables used in `Renderer::replaceMacros()` and the templates they are used in.\n\n";

        foreach ($existingVars as $varName => $templates) {
            $newContent .= "### `$varName`\n\n";
            // Clean up and sort unique
            $uniqueTemplates = [];
            foreach ($templates as $t) {
                if ($t === '') continue;
                $clean = trim($t, '`*');
                if ($clean === 'unknown' || strpos($clean, 'dynamic') !== false) {
                    $clean = '*(dynamic or unknown template)*';
                } else {
                    $clean = "`$clean`";
                }
                if (!in_array($clean, $uniqueTemplates)) {
                    $uniqueTemplates[] = $clean;
                }
            }
            sort($uniqueTemplates);
            foreach ($uniqueTemplates as $template) {
                $newContent .= "- $template\n";
            }
            $newContent .= "\n";
        }

        file_put_contents($filePath, $newContent);
    }
}
