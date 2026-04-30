<?php

namespace PHPDeobfuscator\Analysis;

class DangerousCatalog
{
    private const MAP = [
        // code_exec
        'eval'              => 'code_exec',
        'assert'            => 'code_exec',
        'create_function'   => 'code_exec',
        'preg_replace'      => 'code_exec',
        'preg_filter'       => 'code_exec',
        // os_exec
        'system'            => 'os_exec',
        'exec'              => 'os_exec',
        'shell_exec'        => 'os_exec',
        'passthru'          => 'os_exec',
        'proc_open'         => 'os_exec',
        'popen'             => 'os_exec',
        'pcntl_exec'        => 'os_exec',
        // dispatch
        'call_user_func'        => 'dispatch',
        'call_user_func_array'  => 'dispatch',
        // deser
        'unserialize'       => 'deser',
        // file_write
        'file_put_contents' => 'file_write',
        'fwrite'            => 'file_write',
        'fputs'             => 'file_write',
        'unlink'            => 'file_write',
        'rename'            => 'file_write',
        'copy'              => 'file_write',
        'move_uploaded_file' => 'file_write',
        'chmod'             => 'file_write',
        // network
        'curl_exec'         => 'network',
        'fsockopen'         => 'network',
        'pfsockopen'        => 'network',
        'stream_socket_client' => 'network',
        'socket_create'     => 'network',
        // mail
        'mail'              => 'mail',
        // header_inj
        'header'            => 'header_inj',
        // obfusc
        'base64_decode'     => 'obfusc',
        'gzinflate'         => 'obfusc',
        'gzuncompress'      => 'obfusc',
        'str_rot13'         => 'obfusc',
        'hex2bin'           => 'obfusc',
        'convert_uudecode'  => 'obfusc',
        'pack'              => 'obfusc',
        'unpack'            => 'obfusc',
    ];

    public static function lookup(string $name): ?string
    {
        $key = strtolower($name);
        return self::MAP[$key] ?? null;
    }
}
