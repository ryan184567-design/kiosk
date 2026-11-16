<?php

/* *****************************************
File: HqAwareMigrateCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-16
Description: migrate 的防呆版本，多站模式裸跑時自動使用主站 migration 路徑。
***************************************** */

namespace App\Support\Database\Console;

use Illuminate\Database\Console\Migrations\MigrateCommand;

// 見 UsesHqMigrationPath 的說明。
class HqAwareMigrateCommand extends MigrateCommand
{
    use UsesHqMigrationPath;
}
