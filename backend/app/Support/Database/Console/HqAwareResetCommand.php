<?php

/* *****************************************
File: HqAwareResetCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-16
Description: migrate:reset 的防呆版本，多站模式裸跑時自動使用主站 migration 路徑。
***************************************** */

namespace App\Support\Database\Console;

use Illuminate\Database\Console\Migrations\ResetCommand;

// 見 UsesHqMigrationPath 的說明。
class HqAwareResetCommand extends ResetCommand
{
    use UsesHqMigrationPath;
}
