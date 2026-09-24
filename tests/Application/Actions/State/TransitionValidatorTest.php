<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Menu\State;

use App\Application\State\TransitionMap;
use App\Application\State\TransitionValidator;
use App\Application\State\State;
use PHPUnit\Framework\TestCase;

class TransitionValidatorTest extends TestCase
{
    private TransitionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TransitionValidator(new TransitionMap());
    }

    // ============================================================
    // Null previous state (первый запуск)
    // ============================================================

    public function testNullPreviousStateIsValid(): void
    {
        $this->assertTrue($this->validator->isValid(null, State::Start));
    }

    public function testNullPreviousStateWithAnyTargetIsValid(): void
    {
        $this->assertTrue($this->validator->isValid(null, State::Main_Menu));
        $this->assertTrue($this->validator->isValid(null, State::Share_Contact));
    }

    // ============================================================
    // Standard transitions
    // ============================================================

    public function testStandardTransitionStartToShareContact(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Start, State::Share_Contact)
        );
    }

    public function testStandardTransitionMainMenuToCreateRequest(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Main_Menu, State::Create_Request)
        );
    }

    public function testStandardTransitionMainMenuToUnlockCutter(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Main_Menu, State::Unlock_Cutter)
        );
    }

    public function testStandardTransitionCloseCommandToYes(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Close_Command, State::Yes)
        );
    }

    public function testStandardTransitionCloseCommandToNo(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Close_Command, State::No)
        );
    }

    public function testStandardTransitionCreateNewToStay(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Create_New, State::Stay)
        );
    }

    // ============================================================
    // Special transitions
    // ============================================================

    public function testSpecialTransitionMainMenuToMainMenu(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Main_Menu, State::Main_Menu)
        );
    }

    public function testSpecialTransitionRequestCreatedToRequestCreated(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Request_Created, State::Request_Created)
        );
    }

    public function testSpecialTransitionIssueAlreadyExistToIssueAlreadyExist(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Issue_AlReady_Exist, State::Issue_AlReady_Exist)
        );
    }

    public function testSpecialTransitionExpectedPlotterNumberToMainMenu(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Expected_Plotter_Number, State::Main_Menu)
        );
    }

    public function testSpecialTransitionShareContactToMainMenu(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Share_Contact, State::Main_Menu)
        );
    }

    public function testSpecialTransitionRequestCreatedToCreateNew(): void
    {
        $this->assertTrue(
            $this->validator->isValid(State::Request_Created, State::Create_New)
        );
    }

    // ============================================================
    // Invalid transitions
    // ============================================================

    public function testInvalidTransitionFromMainMenuToFinishRequest(): void
    {
        $this->assertFalse(
            $this->validator->isValid(State::Main_Menu, State::Finish_Request)
        );
    }

    public function testInvalidTransitionFromShareContactToCreateRequest(): void
    {
        $this->assertFalse(
            $this->validator->isValid(State::Share_Contact, State::Create_Request)
        );
    }

    public function testInvalidTransitionFromStartToMainMenu(): void
    {
        $this->assertFalse(
            $this->validator->isValid(State::Start, State::Main_Menu)
        );
    }

    public function testInvalidTransitionFromCloseCommandToCreateRequest(): void
    {
        $this->assertFalse(
            $this->validator->isValid(State::Close_Command, State::Create_Request)
        );
    }
}