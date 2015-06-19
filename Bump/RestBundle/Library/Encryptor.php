<?php

namespace Bump\RestBundle\Library;

class Encryptor
{
    private $secret;

    public function __construct($secret)
    {
        if (strlen($secret)>8) {
            $secret = substr($secret, 0, 8);
        }

        $this->secret = $secret;
    }

    public function decrypt($secret)
    {
        $key = $this->secret;
        $td = mcrypt_module_open(MCRYPT_DES, "", MCRYPT_MODE_ECB, "");
        $iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td), MCRYPT_RAND);
        mcrypt_generic_init($td, $key, $iv);
        $secret = mdecrypt_generic($td, $this->base64urlDecode($secret));
        mcrypt_generic_deinit($td);
        if (substr($secret, 0, 1) != '!') {
            return false;
        }
        $secret = substr($secret, 1, strlen($secret) - 1);

        return trim($secret, "\0");
    }
    public function encrypt($secret)
    {
        $key = $this->secret;
        $td = mcrypt_module_open(MCRYPT_DES, "", MCRYPT_MODE_ECB, "");
        $iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td), MCRYPT_RAND);
        mcrypt_generic_init($td, $key, $iv);
        $secret = $this->base64urlEncode(mcrypt_generic($td, '!'.$secret));
        mcrypt_generic_deinit($td);

        return $secret;
    }

    public function base64urlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function base64urlDecode($data)
    {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
}
