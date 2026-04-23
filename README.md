# henderkes/fork

A PHP library for offloading tasks to child processes, usable during a HTTP request.

Primarily intended and tested against use in [FrankenPHP](https://github.com/php/frankenphp).
Inspired by [spatie/fork](https://github.com/spatie/fork) and [ext-parallel](https://pecl.php.net/package/parallel).

### What does this solve?

ext-parallel can already offload tasks to child threads, however every thread has a fresh php runtime. This isn't a huge issue for simple scripts, however in modern frameworks like Symfony and Laravel, it creates issues because you can't use autowired services without booting up a new Kernel in each thread, or pass any non-serializable data from the parent process.

spatie/fork solves this issue, but the parent process can't do anything while children are started. This library combines ext-parallel's Runtime and Future approach with the Copy-on-Write runtime of `pcntl_fork`.

Other fork libraries generally only work in CLI contexts, work with stdout/stderr or don't fulfil all the requirements I personally have.

### What do you need to know?

The entire runtime is forked, children and parent process share memory initially. This comes with two caveats:

- Database connections or file descriptors are not safe to write to from children. Reading is okay. Circumvent this by re-creating any such resources in the `before` hook of children.
- Existing resources going out of scope could call destructors, which could blow up the parent process. Call `Runtime::abandon()` on them in child processes.
- FrankenPHP automatically force-exits child processes safely.

### Symfony integration

Lets you autowire a pre-configured Runtime for common Symfony scenarios.
Currently, resets all Doctrine database connections or Symfony HttpClients for you automatically. More features may be added over time.

```php
public function report(Runtime $rt, EntityManagerInterface $em): JsonResponse
{
    $posts = $rt->run(fn () => $em->getRepository(Post::class)->findAll());
    $users = $rt->run(fn () => $em->getRepository(User::class)->findAll());
    $books = $em->getRepository(Book::class)->findAll();
    return $this->json([
        'posts' => count($posts->value()),
        'users' => count($users->value()),
        'books' => count($books->value())
    ]);
}
```

For your own services that hold per-process state, implement
`Henderkes\Fork\Symfony\ForkAwareInterface::configure()`. The bundle
auto-tags services with `henderkes_fork.configure` and calls their
`configure(Runtime $runtime): Runtime` method on every Runtime the
container autowires:

```php
final class LegacyClient implements ForkAwareInterface
{
    public function configure(Runtime $runtime): Runtime
    {
        return $runtime
            ->before(child: fn () => $this->reconnect())
            ->after(parent: fn ($result) => $this->log($result));
    }
}
```

Third-party bundles can integrate without a hard dependency on this
library by using the tag directly:

```yaml
# services.yaml
App\Service\LegacyClient:
    tags: [{ name: henderkes_fork.configure, method: configure }]
```

If you have `symfony/flex` enabled, just install this library and you're done. If not, manually enable it:

```php
// bundles.php
return [                                                                                                                                                                                               
  // ...
  Henderkes\Fork\Symfony\ForkBundle::class => ['all' => true],                                                                                                                                       
];

### Laravel integration

Ships a service provider that binds `Henderkes\Fork\Runtime` non-shared.
The bound factory registers a `before(child:)` hook that purges every configured
DB connection in the forked child, so the next query reconnects
lazily.
For other resources the Laravel integration does not cover
(Redis, HTTP, Elasticsearch, …), register your own hooks on the
resolved `Runtime`:

```php
$rt->before(child: function () use ($redis): void {
    $redis->close();
});
```

### Requirements

- PHP 8.5+
- PCNTL extension
- POSIX extension
- Linux, BSD or macOS
- A SAPI compatible with forking threads. Only tested with FrankenPHP.
