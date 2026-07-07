<?php

namespace Database\Seeders\Scenarios;

use Database\Seeders\Mock\AdditionalDataSeeder;
use Database\Seeders\Mock\DepartmentsSeeder;
use Database\Seeders\Mock\MeetingsSeeder;
use Database\Seeders\Mock\OvrsSeeder;
use Database\Seeders\Mock\ProjectsSeeder;
use Database\Seeders\Mock\RiskManagementSeeder;
use Database\Seeders\Mock\SharedExtrasSeeder;
use Database\Seeders\Mock\StrategySeeder;
use Database\Seeders\Mock\SurveysSeeder;
use Database\Seeders\Mock\UnifiedTasksSeeder;
use Database\Seeders\Mock\UsersSeeder;
use Illuminate\Console\Command;

class GenericCompanyScenario
{
    public function __construct(private readonly Command $command) {}

    public function run(): void
    {
        $this->command->info('[Generic] Creating departments...');
        $departments = new DepartmentsSeeder;
        $departments->run();

        $this->command->info('[Generic] Creating users...');
        $users = new UsersSeeder;
        $users->run($departments->departments);

        $this->command->info('[Generic] Creating strategy (portfolios + programs)...');
        $strategy = new StrategySeeder;
        $strategy->run($users->users, $departments->departments);

        $this->command->info('[Generic] Creating projects and tasks...');
        $projects = new ProjectsSeeder;
        $projects->run($users->users, $departments->departments, $strategy->programs);

        $this->command->info('[Generic] Creating surveys...');
        $surveys = new SurveysSeeder;
        $surveys->run($users->users);

        $this->command->info('[Generic] Creating risk management records...');
        $risks = new RiskManagementSeeder;
        $risks->run($users->users, $departments->departments);

        $this->command->info('[Generic] Creating meetings (categories + agenda + attendees)...');
        $meetings = new MeetingsSeeder;
        $meetings->run($users->users, $departments->departments);

        $this->command->info('[Generic] Creating unified tasks (personal/department/recurring)...');
        $tasks = new UnifiedTasksSeeder;
        $tasks->run($users->users, $departments->departments);

        $this->command->info('[Generic] Creating OVR incidents (types + reports + participants + comments + status history)...');
        $ovrs = new OvrsSeeder;
        $ovrs->run($users->users, $departments->departments);

        $this->command->info('[Generic] Creating shared attachments, activity logs and additional comments...');
        $sharedExtras = new SharedExtrasSeeder;
        $sharedExtras->run($users->users, $projects->projects, $meetings->meetings, $risks->risks, $ovrs->reports);

        $this->command->info('[Generic] Creating comments and reviews...');
        $additional = new AdditionalDataSeeder;
        $additional->run($users->users, $projects->projects, $strategy->programs);

        $this->command->info('');
        $this->command->info('Generic company scenario complete.');
        $this->command->info('  Departments : '.count($departments->departments));
        $this->command->info('  Users       : '.count($users->users));
        $this->command->info('  Programs    : '.count($strategy->programs));
        $this->command->info('  Projects    : '.count($projects->projects));
        $this->command->info('  Meetings    : '.count($meetings->meetings));
        $this->command->info('  Risks       : '.count($risks->risks));
        $this->command->info('  OVR reports : '.count($ovrs->reports));
    }
}
