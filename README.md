# wobblefump
Symfony command to calculate binary difference between two files and transform the result using Fourier. It was build 
with large files in mind and keeping a low volatile memory usage. The default setting are capping the actual command 
usage to about 8MB (although it might lower sometimes), but it might appear higher due to Symfony internal operations 
(like 10-30MB higher). 

After running the command you will receive detailed output about the actual memory usage and current progress. This 
command will run a long time (comparing two 7GB .safetensors takes about 6-7h) and will produce a file about twice the 
size of smallest given file (with two files of 7GB it created a ~14GB frequency spectrum).

## Install

```shell
composer require mortimer333/wobblefump
```
and if you use `symfony/flex` it should be it! If not you might want to update `bundles.yaml` file:
```php
<?php

return [
    // ...
    Mortimer333\Wobblefump\WobblefumpBundle::class => ['all' => true],
];
```
No you should be able to see new command being available `wobblefump:diff:fourier`.

## Usage

```shell
# Both files locally
php bin/console wobblefump:diff:fourier ./file_big_v1.bin ./file_big_v2.bin
# Both files on the remote
php bin/console wobblefump:diff:fourier -mB https://cdn.com/file_big_v1 https://cdn.com/file_big_v2
# Only original file is on the remote
php bin/console wobblefump:diff:fourier -mO https://cdn.com/file_big_v1 ./file_big_v2.bin
# Just new file is on the remote
php bin/console wobblefump:diff:fourier -mN ./file_big_v1.bin https://cdn.com/file_big_v2
```

## Advance usage:

```shell
php bin/console wobblefump:diff:fourier -h                                  
Description:
  Transform binary difference between two files using Fourier

Usage:
  wobblefump:diff:fourier [options] [--] <original> <new>

Arguments:
  original                   Path to the original file
  new                        Path to the new file

Options:
  -t, --test-run             Do a test-run (it will cap operations to the 10 chunks)
  -p, --precision=PRECISION  Precision of comparison (int) should be - size of chunks (1 is per one byte; 100 is per 100 bytes).
                             Precision will be rounded down to chunk size and it must be a power of 2. [default: 2048]
  -c, --chunk=CHUNK          The size of chunks (int) to be retrieved from the files. In terms of memory remember to multiply this value twice as it will be per each file. [default: 1048576]
  -o, --output=OUTPUT        Output path for the result [default: "./result.csv"]
  -D, --diff-file=DIFF-FILE  Output path for the difference output. If provided, the diff file will not be removed automatically [default: false]
  -m, --mode[=MODE]          How program should handle the file paths.
                             Modes:
                             O - only original is URL,
                             N - only new is URL,
                             B - both are URL,
                             F - none
                              [default: "F"]
  -h, --help                 Display help for the given command. When no command is given display help for the list command
  -q, --quiet                Do not output any message
  -V, --version              Display this application version
      --ansi|--no-ansi       Force (or disable --no-ansi) ANSI output
  -n, --no-interaction       Do not ask any interactive question
  -e, --env=ENV              The Environment name. [default: "dev"]
      --no-debug             Switch off debug mode.
      --profile              Enables profiling (requires debug).
  -v|vv|vvv, --verbose       Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```


#### Example output:

```
[MEMORY] Starting peak memory usage 44.5MB
Starting Find Diff script...
Script will operate in Dry mode - it will only try up to load 10 chunks
Loading and comparing files...
 6617/6617 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%          36 secs/36 secs          44.5 MiB/48.5 MiB
Diff file has 60 MB
Calculating Fourier Transform on the results...
 30721/30721 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%  3 mins, 20 secs/3 mins, 20 secs  50.5 MiB/48.5 MiB
Command finished successfully and the result was saved into /media/HDD/result.csv
Command took 237.63438200951 seconds to complete
[MEMORY] If memory usage didn't change drastically (like 1MB or 2MB) it will not detect the change:
[MEMORY] End peak memory usage 50.5MB
[MEMORY] Actual peak memory usage for the command: 6MB
```
