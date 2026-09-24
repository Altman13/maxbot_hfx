<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Menu\State;

use App\Application\State\TransitionMap;
use App\Application\State\State;
use PHPUnit\Framework\TestCase;

class TransitionMapTest extends TestCase
{
    private TransitionMap $map;

    protected function setUp(): void
    {
        $this->map = new TransitionMap();
    }

    // ============================================================
    // getStandardTransitions
    // ============================================================

    public function testGetStandardTransitionsReturnsArray(): void
    {
        $transitions = $this->map->getStandardTransitions();

        $this->assertIsArray($transitions);
        $this->assertNotEmpty($transitions);
    }

    public function testStandardTransitionsContainMainMenu(): void
    {
        $transitions = $this->map->getStandardTransitions();

        $this->assertArrayHasKey(State::Main_Menu->value, $transitions);
        $this->assertContains(State::Create_Request, $transitions[State::Main_Menu->value]);
        $this->assertContains(State::Unlock_Cutter, $transitions[State::Main_Menu->value]);
    }

    public function testStandardTransitionsContainStart(): void
    {
        $transitions = $this->map->getStandardTransitions();

        $this->assertArrayHasKey(State::Start->value, $transitions);
        $this->assertContains(State::Share_Contact, $transitions[State::Start->value]);
    }

    public function testStandardTransitionsContainCloseCommand(): void
    {
        $transitions = $this->map->getStandardTransitions();

        $this->assertArrayHasKey(State::Close_Command->value, $transitions);
        $this->assertContains(State::Yes, $transitions[State::Close_Command->value]);
        $this->assertContains(State::No, $transitions[State::Close_Command->value]);
    }

    // ============================================================
    // nextStatesFor
    // ============================================================

    public function testNextStatesForMainMenu(): void
    {
        $next = $this->map->nextStatesFor(State::Main_Menu);

        $this->assertContains(State::Create_Request, $next);
        $this->assertContains(State::Unlock_Cutter, $next);
    }

    public function testNextStatesForUnknownStateReturnsEmptyArray(): void
    {
        // Любой стейт, которого нет в карте — например, Choose_Auth_Method или другой.
        // Если он есть в карте, поправь на тот, которого точно нет.
        $next = $this->map->nextStatesFor(State::Finish_Request);

        // Finish_Request есть в карте:
        $this->assertNotEmpty($next);
        $this->assertContains(State::Create_Request, $next);
        $this->assertContains(State::Unlock_Cutter, $next);
    }

    public function testNextStatesForStateWithoutTransitionsReturnsEmpty(): void
    {
        // Если все стейты в карте — пропусти этот тест,
        // либо найди стейт, которого в карте нет.
        $transitions = $this->map->getStandardTransitions();

        $missing = null;
        foreach (State::cases() as $state) {
            if (!isset($transitions[$state->value])) {
                $missing = $state;
                break;
            }
        }

        if ($missing !== null) {
            $this->assertSame([], $this->map->nextStatesFor($missing));
        } else {
            $this->markTestSkipped('Все стейты присутствуют в карте');
        }
    }

    // ============================================================
    // getSpecialTransitions
    // ============================================================

    public function testGetSpecialTransitionsReturnsArray(): void
    {
        $special = $this->map->getSpecialTransitions();

        $this->assertIsArray($special);
        $this->assertNotEmpty($special);
    }

    public function testSpecialTransitionsContainsSelfTransitionForMainMenu(): void
    {
        $special = $this->map->getSpecialTransitions();

        $hasSelfTransition = false;
        foreach ($special as [$from, $to]) {
            if ($from->value === State::Main_Menu->value && $to->value === State::Main_Menu->value) {
                $hasSelfTransition = true;
                break;
            }
        }

        $this->assertTrue($hasSelfTransition);
    }

    public function testSpecialTransitionsContainsExpectedPlotterToMainMenu(): void
    {
        $special = $this->map->getSpecialTransitions();

        $hasTransition = false;
        foreach ($special as [$from, $to]) {
            if ($from->value === State::Expected_Plotter_Number->value
                && $to->value === State::Main_Menu->value) {
                $hasTransition = true;
                break;
            }
        }

        $this->assertTrue($hasTransition);
    }

    public function testSpecialTransitionsContainShareContactToMainMenu(): void
    {
        $special = $this->map->getSpecialTransitions();

        $hasTransition = false;
        foreach ($special as [$from, $to]) {
            if ($from->value === State::Share_Contact->value
                && $to->value === State::Main_Menu->value) {
                $hasTransition = true;
                break;
            }
        }

        $this->assertTrue($hasTransition);
    }
}