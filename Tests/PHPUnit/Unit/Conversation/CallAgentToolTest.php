<?php

namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\AI\Tools\CallAgentTool;
use axenox\GenAI\Common\Selectors\AiAgentSelector;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Model\ModelInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies prompt isolation and the delegation boundary without running an LLM.
 */
class CallAgentToolTest extends TestCase
{
    /**
     * The configured version reaches the resolver and only the delegated prompt reaches the target.
     */
    public function testDelegatesInASeparateConversation() : void
    {
        $workbench = new Workbench();
        $caller = $this->createStub(AiAgentInterface::class);
        $caller->method('getSelector')->willReturn(new AiAgentSelector($workbench, 'test.App.Caller:1.0'));
        $target = $this->createAgent($workbench, 'test.App.Target:2.1');
        $tool = $this->getMockBuilder(CallAgentTool::class)
            ->setConstructorArgs([$workbench, new UxonObject(['agent_alias' => 'test.App.Target:^2.0'])])
            ->onlyMethods(['resolveAgent'])
            ->getMock();
        $tool->expects(self::once())->method('resolveAgent')->with('test.App.Target:^2.0')->willReturn($target);

        $response = $this->createStub(AiResponseInterface::class);
        $response->method('getMessage')->willReturn('Delegated reply');
        $target->expects(self::once())->method('handle')->willReturnCallback(
            static function (AiPromptInterface $prompt) use ($response) : AiResponseInterface {
                self::assertSame('Review this policy.', $prompt->getUserPrompt());
                self::assertNull($prompt->getConversationUid());
                self::assertSame([], $prompt->getFiles());
                self::assertFalse($prompt->hasInputData());
                return $response;
            }
        );

        $task = new GenericTask($workbench);
        $task->setParameter('conversation', 'caller-conversation');
        $result = $tool->invoke($caller, $task, $tool->normalizeArguments(['prompt' => 'Review this policy.']));
        self::assertSame('Delegated reply', $result->getValue());
        self::assertSame('caller-conversation', $task->getParameter('conversation'));
    }

    /**
     * The Log Analyzer receives the configured object and the argument as a literal cell value.
     */
    public function testPassesConfiguredInputDataToTarget() : void
    {
        $workbench = $this->getMockBuilder(Workbench::class)->onlyMethods(['model'])->getMock();
        $model = $this->createMock(ModelInterface::class);
        $object = $this->createStub(MetaObjectInterface::class);
        $object->method('getModel')->willReturn($model);
        $object->method('getAliasWithNamespace')->willReturn('exface.Core.LOG_ENTRY');
        $model->method('getWorkbench')->willReturn($workbench);
        $model->expects(self::atLeastOnce())->method('getObject')->with('exface.Core.LOG_ENTRY')->willReturn($object);
        $workbench->expects(self::atLeastOnce())->method('model')->willReturn($model);

        $caller = $this->createStub(AiAgentInterface::class);
        $caller->method('getSelector')->willReturn(new AiAgentSelector($workbench, 'test.App.Caller:1.0'));
        $target = $this->createAgent($workbench, 'axenox.GenAI.log_analyzer_assistant:2.0');
        $tool = $this->getMockBuilder(CallAgentTool::class)
            ->setConstructorArgs([$workbench, new UxonObject([
                'agent_alias' => 'axenox.GenAI.log_analyzer_assistant',
                'arguments' => [
                    ['name' => 'prompt', 'required' => true],
                    ['name' => 'log_id', 'required' => true]
                ],
                'input_data' => [
                    'object_alias' => 'exface.Core.LOG_ENTRY',
                    'rows' => [['id' => '[#log_id#]']]
                ]
            ])])
            ->onlyMethods(['resolveAgent'])
            ->getMock();
        $tool->expects(self::once())->method('resolveAgent')->willReturn($target);

        $logId = 'id "with quotes" and [#prompt#]';
        $response = $this->createStub(AiResponseInterface::class);
        $response->method('getMessage')->willReturn('Analysis');
        $target->expects(self::once())->method('handle')->willReturnCallback(
            static function (AiPromptInterface $prompt) use ($response, $object, $logId) : AiResponseInterface {
                self::assertSame('Analyze the error.', $prompt->getUserPrompt());
                self::assertSame($object, $prompt->getMetaObject());
                self::assertSame([['id' => $logId]], $prompt->getInputData()->getRows());
                return $response;
            }
        );

        $args = $tool->normalizeArguments(['log_id' => $logId, 'prompt' => 'Analyze the error.']);
        self::assertSame('Analysis', $tool->invoke($caller, new GenericTask($workbench), $args)->getValue());
    }

    /**
     * A nested call cannot return to its original caller, even through a different version.
     */
    public function testRejectsAnIndirectCycleAndClearsItAfterFailure() : void
    {
        $workbench = new Workbench();
        $first = $this->createAgent($workbench, 'test.App.First:1.0');
        $second = $this->createAgent($workbench, 'test.App.Second:2.0');
        $outer = $this->getMockBuilder(CallAgentTool::class)
            ->setConstructorArgs([$workbench, new UxonObject(['agent_alias' => 'test.App.Second'])])
            ->onlyMethods(['resolveAgent'])
            ->getMock();
        $outer->expects(self::once())->method('resolveAgent')->willReturn($second);
        $inner = $this->getMockBuilder(CallAgentTool::class)
            ->setConstructorArgs([$workbench, new UxonObject(['agent_alias' => 'test.App.First:2.0'])])
            ->onlyMethods(['resolveAgent'])
            ->getMock();
        $inner->expects(self::once())->method('resolveAgent')->willReturn($first);

        $task = new GenericTask($workbench);
        $second->expects(self::once())->method('handle')->willReturnCallback(
            static function (AiPromptInterface $prompt) use ($inner, $second) : AiResponseInterface {
                $inner->invoke($second, $prompt, ['Send back to first']);
                self::fail('A recursive call must be rejected before the target handles it.');
            }
        );
        $response = $this->createStub(AiResponseInterface::class);
        $response->method('getMessage')->willReturn('New delegation succeeded');
        $first->expects(self::once())->method('handle')->willReturn($response);

        try {
            $outer->invoke($first, $task, ['Delegate to second']);
            self::fail('Expected an indirect recursion error.');
        } catch (AiToolRuntimeError $exception) {
            self::assertStringContainsString('Recursive agent call', $exception->getMessage());
        }

        self::assertSame('New delegation succeeded', $inner->invoke($second, $task, ['Ask first'])->getValue());
    }

    /**
     * Build a target with its resolved selector but no model connection.
     */
    private function createAgent(Workbench $workbench, string $selector) : AiAgentInterface
    {
        $agent = $this->createMock(AiAgentInterface::class);
        $agent->method('getSelector')->willReturn(new AiAgentSelector($workbench, $selector));
        return $agent;
    }
}
