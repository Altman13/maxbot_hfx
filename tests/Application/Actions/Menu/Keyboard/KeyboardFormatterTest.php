<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Menu\Keyboard;

use App\Application\Actions\Menu\Keyboard\KeyboardFormatter;
use PHPUnit\Framework\TestCase;

class KeyboardFormatterTest extends TestCase
{
    private KeyboardFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new KeyboardFormatter();
    }

    // ============================================================
    // Формат ответа
    // ============================================================

    public function testFormatReturnsExpectedKeys(): void
    {
        $result = $this->formatter->format([]);

        $this->assertArrayHasKey('keyboard', $result);
        $this->assertArrayHasKey('resize_keyboard', $result);
        $this->assertArrayHasKey('one_time_keyboard', $result);
    }

    public function testFormatReturnsEmptyKeyboardForEmptyButtons(): void
    {
        $result = $this->formatter->format([]);

        $this->assertSame([], $result['keyboard']);
    }

    // ============================================================
    // Reply keyboard (по умолчанию)
    // ============================================================

    public function testFormatReplyKeyboardSetsOneTimeTrue(): void
    {
        $buttons = [
            ['text' => 'Кнопка 1', 'callback_data' => 'action_1'],
        ];

        $result = $this->formatter->format($buttons, KeyboardFormatter::TYPE_REPLY);

        $this->assertTrue($result['one_time_keyboard']);
        $this->assertTrue($result['resize_keyboard']);
    }

    public function testFormatReplyKeyboardStripsCallbackData(): void
    {
        $buttons = [
            ['text' => 'Кнопка 1', 'callback_data' => 'action_1'],
        ];

        $result = $this->formatter->format($buttons, KeyboardFormatter::TYPE_REPLY);

        // В reply-клавиатуре callback_data не должно быть
        $this->assertArrayNotHasKey('callback_data', $result['keyboard'][0][0]);
        $this->assertSame('Кнопка 1', $result['keyboard'][0][0]['text']);
    }

    // ============================================================
    // Inline keyboard
    // ============================================================

    public function testFormatInlineKeyboardKeepsCallbackData(): void
    {
        $buttons = [
            ['text' => 'Кнопка 1', 'callback_data' => 'action_1'],
        ];

        $result = $this->formatter->format($buttons, KeyboardFormatter::TYPE_INLINE);

        $this->assertArrayHasKey('callback_data', $result['keyboard'][0][0]);
        $this->assertSame('action_1', $result['keyboard'][0][0]['callback_data']);
        $this->assertSame('Кнопка 1', $result['keyboard'][0][0]['text']);
    }

    public function testFormatInlineKeyboardSetsOneTimeFalse(): void
    {
        $buttons = [
            ['text' => 'Кнопка 1', 'callback_data' => 'action_1'],
        ];

        $result = $this->formatter->format($buttons, KeyboardFormatter::TYPE_INLINE);

        $this->assertFalse($result['one_time_keyboard']);
    }

    // ============================================================
    // Структура rows
    // ============================================================

    public function testFormatGroupsEachButtonInSeparateRow(): void
    {
        $buttons = [
            ['text' => 'Кнопка 1', 'callback_data' => 'action_1'],
            ['text' => 'Кнопка 2', 'callback_data' => 'action_2'],
            ['text' => 'Кнопка 3', 'callback_data' => 'action_3'],
        ];

        $result = $this->formatter->format($buttons);

        $this->assertCount(3, $result['keyboard']);
        $this->assertCount(1, $result['keyboard'][0]);
        $this->assertCount(1, $result['keyboard'][1]);
        $this->assertCount(1, $result['keyboard'][2]);
    }

    public function testFormatKeepsButtonTextUntouched(): void
    {
        $buttons = [
            ['text' => 'Создать заявку', 'callback_data' => 'Create_Request'],
        ];

        $result = $this->formatter->format($buttons);

        $this->assertSame('Создать заявку', $result['keyboard'][0][0]['text']);
    }

    // ============================================================
    // Константы
    // ============================================================

    public function testTypeConstantsAreStrings(): void
    {
        $this->assertSame('inline', KeyboardFormatter::TYPE_INLINE);
        $this->assertSame('reply', KeyboardFormatter::TYPE_REPLY);
    }

    // ============================================================
    // reply_markup для кнопки request_contact (без callback_data)
    // ============================================================

    public function testFormatButtonWithoutCallbackDataInReplyMode(): void
    {
        $buttons = [
            ['text' => 'Поделиться контактом', 'request_contact' => true],
        ];

        $result = $this->formatter->format($buttons, KeyboardFormatter::TYPE_REPLY);

        // В reply-режиме кнопка без callback_data просто становится text-кнопкой
        $this->assertArrayNotHasKey('callback_data', $result['keyboard'][0][0]);
        $this->assertSame('Поделиться контактом', $result['keyboard'][0][0]['text']);
    }
}