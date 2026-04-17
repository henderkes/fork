# henderkes/fork

A PHP library for offloading tasks to child processes, usable during a HTTP request.

Primarily intended and tested against use in [FrankenPHP](https://github.com/php/frankenphp).
Inspired by [spatie/fork](https://github.com/spatie/fork) and [ext-parallel](https://pecl.php.net/package/parallel).

### What does this solve?

ext-parallel can already offload tasks to child threads, however every thread has a fresh php runtime. This isn't a huge issue for simple scripts, however in modern frameworks like Symfony and Laravel, it creates issues because you can't use autowired services without booting up a new Kernel in each thread, or any non-serializable data from the parent process.

spatie/fork solves this issue, but the parent process can't do anything while children are started. This library combines ext-parallel's Runtime and Future approach with the Copy-on-Write runtime of `pcntl_fork`.

### What do you need to know?

The entire runtime is forked, children and parent process share memory initially. This comes with two caveats:

- Database connections or file descriptors are not safe to write to from children. Reading is okay. Circumvent this by re-creating any such resources in the `before` hook of children.
- Existing resources going out of scope could call destructors, which could blow up the parent process. Keep a reference to them until you call exit() in the child threads.
- FrankenPHP automatically force-exits child processes safely.

### Symfony/Laravel integration

WIP, will add default support for Symfony to automatically reconnect Doctrine, HttpClient and others.

### Requirements

- PHP 8.5+ ZTS
- PCNTL extension
- POSIX operating system (Linux, macOS, BSD)
- A SAPI compatible with forking threads. Only tested with FrankenPHP.