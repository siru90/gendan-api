<?php

namespace App\Ok;

class OkEncrypt
{

    private static string $passphrase = '<;73]W^vR0~umn{l[2PrR2StR|;S[Zt2@sLfUtj{zbT6S=Hy?8Y[82{@ugMq|wU3u{uw0?UDtJO';

    private static string $cipher_algo = 'aes-256-cbc';

    public static function encrypt($data): false|string
    {
        $ivLen = openssl_cipher_iv_length(self::$cipher_algo);
        $iv = openssl_random_pseudo_bytes($ivLen);
        $encrypt = openssl_encrypt($data, self::$cipher_algo, self::$passphrase, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv) . '.' . base64_encode($encrypt);
    }

    public static function decrypt($data): false|string
    {
        [$iv, $encrypt] = explode('.', $data, 2);
        $iv = base64_decode($iv);
        $encrypt = base64_decode($encrypt);
        return openssl_decrypt($encrypt, self::$cipher_algo, self::$passphrase, OPENSSL_RAW_DATA, $iv);
    }
}
