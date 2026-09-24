<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Menu\Menu;

use App\Application\Actions\Menu\Keyboard\KeyboardFormatter;
use App\Application\Actions\Menu\MenuResponseBuilder;
use PHPUnit\Framework\TestCase;

class MenuResponseBuilderTest extends TestCase
{
    private MenuResponseBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MenuResponseBuilder(new KeyboardFormatter());
    }

    // ============================================================
    // Без кнопок
    // ============================================================

    public function testBuildWithoutButtonsReturnsChatIdAndText(): void
    {
        $result = $this->builder->build('123', 'Привет');

        $this->assertSame('123', $result['chat_id']);
        $this->assertSame('Привет', $result['text']);
        $this->assertArrayNotHasKey('reply_markup', $result);
    }

    public function testBuildWithEmptyButtonsArrayOmitsReplyMarkup(): void
    {
        $result = $this->builder->build('123', 'Привет', []);

        $this->assertArrayNotHasKey('reply_markup', $result);
    }

    // ============================================================
    // С кнопками
    // ============================================================

    public function testBuildWithButtonsIncludesReplyMarkup(): void
    {
        $buttons = [
            ['text' => 'Создать', 'callback_data' => 'Create_Request'],
        ];

        $result = $this->builder->build('123', 'Меню', $buttons);

        $this->assertArrayHasKey('reply_markup', $result);
        $this->assertArrayHasKey('keyboard', $result['reply_markup']);
        $this->assertArrayHasKey('resize_keyboard', $result['reply_markup']);
        $this->assertArrayHasKey('one_time_keyboard', $result['reply_markup']);
    }

    public function testBuildWithButtonsPassesButtonsToKeyboard(): void
    {
        $buttons = [
            ['text' => 'Создать', 'callback_data' => 'Create_Request'],
            ['text' => 'Разблокировать', 'callback_data' => 'Unlock_Cutter'],
        ];

        $result = $this->builder->build('123', 'Меню', $buttons);

        $this->assertCount(2, $result['reply_markup']['keyboard']);
        $this->assertSame('Создать', $result['reply_markup']['keyboard'][0][0]['text']);
        $this->assertSame('Разблокировать', $result['reply_markup']['keyboard'][1][0]['text']);
    }

    // ============================================================
    // Keyboard type
    // ============================================================

    public function testBuildWithReplyTypeOmitsCallbackData(): void
    {
        $buttons = [
            ['text' => 'Кнопка', 'callback_data' => 'action'],
        ];

        $result = $this->builder->build(
            '123',
            'Текст',
            $buttons,
            KeyboardFormatter::TYPE_REPLY
        );

        $this->assertFalse($result['reply_markup']['one_time_keyboard'] === false && false);
        $this->assertArrayNotHasKey('callback_data', $result['reply_markup']['keyboard'][0][0]);
        $this->assertTrue($result['reply_markup']['one_time_keyboard']);
    }

    public function testBuildWithInlineTypeKeepsCallbackData(): void
    {
        $buttons = [
            ['text' => 'Кнопка', 'callback_data' => 'action'],
        ];

        $result = $this->builder->build(
            '123',
            'Текст',
            $buttons,
            KeyboardFormatter::TYPE_INLINE
        );

        $this->assertArrayHasKey('callback_data', $result['reply_markup']['keyboard'][0][0]);
        $this->assertSame('action', $result['reply_markup']['keyboard'][0][0]['callback_data']);
        $this->assertFalse($result['reply_markup']['one_time_keyboard']);
    }

    // ============================================================
    // Default keyboard type
    // ============================================================

    public function testBuildUsesReplyByDefault(): void
    {
        $buttons = [
            ['text' => 'Кнопка', 'callback_data' => 'action'],
        ];

        $result = $this->builder->build('123', 'Текст', $buttons);

        // По умолчанию reply — callback_data должно быть удалено
        $this->assertArrayNotHasKey('callback_data', $result['reply_markup']['keyboard'][0][0]);
        $this->assertTrue($result['reply_markup']['one_time_keyboard']);
    }

    // ============================================================
    // chat_id как строка
    // ============================================================

    public function testBuildKeepsChatIdAsString(): void
    {
        $result = $this->builder->build('999', 'Текст');

        $this->assertSame('999', $result['chat_id']);
        $this->assertIsString($result['chat_id']);
    }

    public function testBuildKeepsTextAsIs(): void
    {
        $text = "Многострочный\nтекст с эмодзи 🎉";

        $result = $this->builder->build('123', $text);

        $this->assertSame($text, $result['text']);
    }
}