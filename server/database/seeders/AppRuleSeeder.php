<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::templates() as $family => $rules) {
            foreach ($rules as $app => $category) {
                DB::table('role_app_rules')->insertOrIgnore(['cohort_key' => 'template:'.$family, 'app_name' => $app, 'category' => $category, 'reason' => 'Suggested '.$family.' default; administrator must review suitability.', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public static function templates(): array
    {
        return [
            'agency' => ['figma.exe' => 'work_related', 'photoshop.exe' => 'work_related', 'illustrator.exe' => 'work_related', 'indesign.exe' => 'work_related', 'adobe premiere pro.exe' => 'work_related', 'afterfx.exe' => 'work_related', 'chrome.exe' => 'work_related', 'msedge.exe' => 'work_related', 'firefox.exe' => 'work_related', 'brave.exe' => 'work_related', 'slack.exe' => 'neutral', 'teams.exe' => 'neutral', 'ms-teams.exe' => 'neutral', 'discord.exe' => 'review_context', 'telegram.exe' => 'review_context', 'steam.exe' => 'review_context', 'epicgameslauncher.exe' => 'review_context'],
            'devshop' => ['code.exe' => 'work_related', 'devenv.exe' => 'work_related', 'idea64.exe' => 'work_related', 'pycharm64.exe' => 'work_related', 'webstorm64.exe' => 'work_related', 'windowsterminal.exe' => 'work_related', 'powershell.exe' => 'work_related', 'pwsh.exe' => 'work_related', 'cmd.exe' => 'work_related', 'bash.exe' => 'work_related', 'git.exe' => 'work_related', 'githubdesktop.exe' => 'work_related', 'chrome.exe' => 'work_related', 'msedge.exe' => 'work_related', 'firefox.exe' => 'work_related', 'brave.exe' => 'work_related', 'slack.exe' => 'neutral', 'teams.exe' => 'neutral', 'ms-teams.exe' => 'neutral', 'steam.exe' => 'review_context', 'epicgameslauncher.exe' => 'review_context'],
            'general' => ['chrome.exe' => 'neutral', 'msedge.exe' => 'neutral', 'firefox.exe' => 'neutral', 'teams.exe' => 'neutral', 'discord.exe' => 'neutral', 'steam.exe' => 'review_context'],
            'engineering' => ['code.exe' => 'work_related', 'devenv.exe' => 'work_related', 'idea64.exe' => 'work_related', 'git.exe' => 'work_related'],
            'office' => ['chrome.exe' => 'work_related', 'msedge.exe' => 'work_related', 'firefox.exe' => 'work_related', 'brave.exe' => 'work_related', 'thunderbird.exe' => 'work_related', 'slack.exe' => 'neutral', 'teams.exe' => 'neutral', 'ms-teams.exe' => 'neutral', 'steam.exe' => 'review_context', 'epicgameslauncher.exe' => 'review_context', 'discord.exe' => 'review_context', 'telegram.exe' => 'review_context', 'excel.exe' => 'work_related', 'winword.exe' => 'work_related', 'powerpnt.exe' => 'work_related', 'outlook.exe' => 'work_related'],
            'design' => ['figma.exe' => 'work_related', 'photoshop.exe' => 'work_related', 'illustrator.exe' => 'work_related'],
        ];
    }
}
