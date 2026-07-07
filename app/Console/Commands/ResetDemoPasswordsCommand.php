<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetDemoPasswordsCommand extends Command
{
    protected $signature = 'users:reset-demo-passwords {--include-demo : إعادة ضبط كلمات مرور جميع المستخدمين بنطاق @demo.com أيضاً}';

    protected $description = 'إعادة ضبط كلمات مرور الحسابات التجريبية إلى password';

    /**
     * @var array<int, string>
     */
    private array $coreDemoEmails = [
        'admin@admin.com',
        'manager@admin.com',
        'pm@admin.com',
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'staging'])) {
            $this->error('This command is not available in production.');

            return self::FAILURE;
        }

        $password = Hash::make('password');
        $count = 0;

        foreach ($this->coreDemoEmails as $email) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->warn("⚠️  المستخدم غير موجود: {$email}");

                continue;
            }

            $user->password = $password;
            $user->save();
            $count++;

            $this->info("✅ تم إعادة ضبط كلمة مرور: {$email}");
        }

        if ($this->option('include-demo')) {
            $demoUsers = User::where('email', 'like', '%@demo.com')->get();

            foreach ($demoUsers as $user) {
                $user->password = $password;
                $user->save();
                $count++;

                $this->info("✅ تم إعادة ضبط كلمة مرور: {$user->email}");
            }
        }

        $this->info("تم إعادة ضبط {$count} كلمة مرور.");

        return self::SUCCESS;
    }
}
