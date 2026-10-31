<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures;

class ArticlePolicy
{
    public static bool $viewAny = true;

    public static bool $view = true;

    public static bool $update = true;

    public static bool $delete = true;

    public static function reset(): void
    {
        static::$viewAny = static::$view = static::$update = static::$delete = true;
    }

    public function viewAny(User $user): bool
    {
        return static::$viewAny;
    }

    public function view(User $user, Article $article): bool
    {
        return static::$view;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Article $article): bool
    {
        return static::$update;
    }

    public function delete(User $user, Article $article): bool
    {
        return static::$delete;
    }
}
