# wobblefump
Symfony command to calculate binary difference between two files and transform the result using Fourier.

## Install

```shell
composer require mortimer333/wobblefump
```

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
