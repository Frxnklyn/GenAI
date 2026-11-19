<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Conversation;

use axenox\GenAI\Common\AiToolCall;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Common\Messages\ToolCallMessage;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that file metadata is quoted only in the designer preview.
 */
class ToolResultFrontmatterTest extends TestCase
{
    /**
     * File metadata must render as quoted YAML rather than a Setext heading.
     *
     * @return void
     */
    public function testFileFrontmatterRendersAsQuotedCodeWithoutChangingRawResult() : void
    {
        $workbench = new Workbench();
        $value = "---\ntype: File\ntitle: example.php\nresource: example.php\nmimetype: text/x-php\n---"
            . "\n\n```php\n<?php echo 'hello';\n```";
        $result = new AiToolResultString(
            $workbench,
            $value,
            DataTypeFactory::createFromPrototype($workbench, MarkdownDataType::class)
        );
        $message = new ToolCallMessage('', [
            new AiToolCall('ReadVendorFile', 'read', new \stdClass())
        ], ['read' => $result]);

        $markdown = $message->toMarkdown();
        self::assertStringContainsString('> ```yaml-frontmatter', $markdown);
        $html = MarkdownDataType::convertMarkdownToHtml($markdown);
        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('<code class="language-yaml-frontmatter">', $html);
        self::assertStringNotContainsString('<h2>type: File', $html);
        self::assertStringContainsString('<code class="language-php">', $html);
        self::assertSame($value, $result->getValue());
        self::assertSame($value, $result->getValueWithMetadata());
    }

    /**
     * Markdown without leading metadata keeps its headings, rules and tables.
     *
     * @return void
     */
    public function testOrdinaryMarkdownIsNotQuoted() : void
    {
        $workbench = new Workbench();
        $value = "# Results\n\n---\n\n| File | Matches |\n| --- | --- |\n| example.php | 1 |";
        $message = new ToolCallMessage('', [
            new AiToolCall('SearchFiles', 'search', new \stdClass())
        ], ['search' => new AiToolResultString(
            $workbench,
            $value,
            DataTypeFactory::createFromPrototype($workbench, MarkdownDataType::class)
        )]);

        $markdown = $message->toMarkdown();
        self::assertStringContainsString($value, $markdown);
        self::assertStringNotContainsString('yaml-frontmatter', $markdown);
    }
}
