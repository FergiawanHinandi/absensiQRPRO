<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;

class VerifyAndActivateAccountsCommandTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_parses_all_official_roles_by_default(): void
    {
        $command = $this->makeCommand();

        $roles = $this->invokePrivateMethod($command, 'parseRoles', ['']);

        $this->assertSame([
            'super_admin',
            'admin',
            'school_admin',
            'principal',
            'vice_principal',
            'teacher',
            'homeroom_teacher',
            'staff',
            'student',
            'parent',
        ], $roles);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_unknown_roles(): void
    {
        $command = $this->makeCommand();

        $roles = $this->invokePrivateMethod($command, 'parseRoles', ['teacher,alien']);

        $this->assertNull($roles);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_requires_teacher_role_for_homeroom_teachers(): void
    {
        $command = $this->makeCommand();

        $user = new User();
        $user->role_type = 'homeroom_teacher';

        $requiredRoles = $this->invokePrivateMethod($command, 'requiredRolesForUser', [$user]);

        $this->assertSame(['homeroom_teacher', 'teacher'], $requiredRoles);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_only_requires_the_declared_role_for_regular_teachers(): void
    {
        $command = $this->makeCommand();

        $user = new User();
        $user->role_type = 'teacher';

        $requiredRoles = $this->invokePrivateMethod($command, 'requiredRolesForUser', [$user]);

        $this->assertSame(['teacher'], $requiredRoles);
    }

    private function makeCommand(): object
    {
        return app(\App\Console\Commands\VerifyAndActivateAccounts::class);
    }

    /**
     * Invoke a private/protected method without coupling the test to DB state.
     *
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivateMethod(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $methodRef = $reflection->getMethod($method);
        $methodRef->setAccessible(true);

        return $methodRef->invokeArgs($object, $arguments);
    }
}