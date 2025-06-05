<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;

class Test2 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:test2';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test command1';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        ini_set('xdebug.var_display_max_children', 999);
        ini_set('xdebug.var_display_max_depth', 5);

        /*$quotes = Inspiring::quotes()->toArray();
        $array = [
            12 => 'user1@bilibiliwx.com',
            13 => 'user2@bilibiliwx.com',
            14 => 'user3@bilibiliwx.com',
            15 => 'user4@bilibiliwx.com',
            16 => 'user5@bilibiliwx.com',
            17 => 'user6@bilibiliwx.com',
        ];

        for ($i = 100000; $i--;) {

            $item = array_rand($array, 2);

            echo "$i ", $array[$item[0]], "[{$item[0]}]", " => ", $array[$item[1]], "[{$item[1]}]", "\n";

            $account = \App\Services\Accounts::getInstance()->getAccountById($item[0]);
            $mailer = \App\Ok\OkSMTP::getInstance()->getMailer($account);
            $mailer->SMTPAutoTLS = false;
            $mailer->SMTPSecure = false;

            $address = $array[$item[1]];
            $mailer->addAddress($address);

            $mailer->Subject = $quotes[array_rand($quotes)];
            $mailer->Body = chunk_split(rand(1, 9999999) . " " . base64_encode(random_bytes(rand(1, 99999))));

            $mailer->send();

            sleep(5);
        }*/

        //        $account = \App\Services\Accounts::getInstance()->getAccountById($id = 13);
        //        \App\Jobs\SyncFolders::dispatchSync($account);
        //
        //        $account = \App\Services\Accounts::getInstance()->getAccountById($accountId = 9);
        //        $clientNew = \App\Ok\OkIMAP::getInstance()->imapClient($account);
        //        $jj = $clientNew->send("SELECT INBOX");
        //        var_dump($jj);
        //        $jj = $clientNew->send("STORE 3 +FLAGS (\Seen)");
        //        var_dump($jj);

        return Command::SUCCESS;
    }
}
