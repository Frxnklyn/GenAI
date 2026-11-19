<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Tools;

use axenox\GenAI\AI\Tools\GetDocsTool;
use axenox\GenAI\AI\Tools\UxonValidateTool;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\Tasks\TaskInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies optional schema handling through real UXON validation without starting Workbench.
 */
class UxonValidateToolTest extends TestCase
{
    /**
     * Optional schemas must not prevent validation of a tool with explicit prototype context.
     * 
     * @param array<string,string|null> $schemaArguments
     * @return void
     */
    #[DataProvider('schemaArguments')]
    public function testValidatesToolUxon(array $schemaArguments) : void
    {
        $workbench = new Workbench([
            'SERVER.INSTALLATION_NAME' => 'uxon-validate-tool-test',
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]);
        $tool = new UxonValidateTool($workbench);
        $arguments = $tool->normalizeArguments(array_merge([
            'uxon' => [
                'alias' => 'axenox.GenAI.GetDocsTool',
                'description' => 'Read linked documentation.',
                'name' => 'GetDocs'
            ],
            'prototype' => '\\' . GetDocsTool::class
        ], $schemaArguments));

        $result = $tool->invoke(
            $this->createStub(AiTaskHandlerInterface::class),
            $this->createStub(TaskInterface::class),
            $arguments
        );

        self::assertSame([], $result->getExceptions());
        self::assertSame([], json_decode((string) $result, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string,array{array<string,string|null>}>
     */
    public static function schemaArguments() : iterable
    {
        yield 'omitted schema' => [[]];
        yield 'null schema' => [['schema' => null]];
        yield 'empty schema' => [['schema' => '']];
        yield 'explicit schema' => [['schema' => '\\exface\\Core\\Uxon\\UxonSchema']];
    }
}
