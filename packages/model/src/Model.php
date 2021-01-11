<?php

namespace Squire;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent;
use Illuminate\Support\Str;

class Model extends Eloquent\Model
{
    public $incrementing = false;

    public static $schema = [];

    protected static $sqliteConnection;

    protected static function boot()
    {
        parent::boot();

        if (static::hasValidCache()) {
            static::setSqliteConnection(static::getCachePath());

            return;
        }

        if (static::isCacheable()) {
            static::cache([
                Repository::getLocale(static::class),
            ]);

            return;
        }

        static::setSqliteConnection(':memory:');

        static::migrate();
    }

    public static function cache($locales = [])
    {
        if (! static::isCacheable()) return false;

        if (! count($locales)) {
            $locales = array_keys(Repository::getSources(static::class));
        }

        collect($locales)->filter(function ($locale) {
            return Repository::sourceIsRegistered(static::class, $locale);
        })->each(function ($locale) {
            $cachePath = static::getCachePath($locale);

            file_put_contents($cachePath, '');

            static::setSqliteConnection($cachePath);

            static::migrate($locale);

            $modelUpdatedAt = static::getModelUpdatedAt();
            $sourceUpdatedAt = static::getSourceUpdatedAt($locale);

            touch($cachePath, $modelUpdatedAt >= $sourceUpdatedAt ? $modelUpdatedAt : $sourceUpdatedAt);
        });
        
        return false;
    }

    protected static function getCachedAt($locale = null)
    {
        $cachePath = static::getCachePath($locale);

        return file_exists($cachePath) ? filemtime($cachePath) : 0;
    }

    protected static function getCacheDirectory()
    {
        return realpath(config('squire.cache-path', storage_path('framework/cache')));
    }

    protected static function getCacheFileName($locale = null)
    {
        $kebabCaseLocale = Str::of($locale ?? Repository::getLocale(static::class))->replace('_', '-')->lower();
        $kebabCaseModelClassName = Str::of( static::class)->replace('\\', '')->kebab();

        return config('squire.cache-prefix', 'squire').'-'.$kebabCaseModelClassName.'-'.$kebabCaseLocale.'.sqlite';
    }

    protected static function getCachePath($locale = null)
    {
        return static::getCacheDirectory().'/'.static::getCacheFileName($locale);
    }
    
    protected static function getModelUpdatedAt()
    {
        return filemtime((new \ReflectionClass(static::class))->getFileName());
    }

    protected static function getSourceUpdatedAt($locale = null)
    {
        return filemtime(Repository::getSource(static::class, $locale));
    }

    protected static function hasValidCache($locale = null)
    {
        $cachedAt = static::getCachedAt($locale);
        $modelUpdatedAt = static::getModelUpdatedAt();
        $sourceUpdatedAt = static::getSourceUpdatedAt($locale);

        return $modelUpdatedAt <= $cachedAt && $sourceUpdatedAt <= $cachedAt;
    }

    protected static function isCacheable()
    {
        $cacheDirectory = static::getCacheDirectory();

        return file_exists($cacheDirectory) && is_writable($cacheDirectory);
    }

    public static function migrate($locale = null)
    {
        $tableName = (new static)->getTable();

        static::resolveConnection()->getSchemaBuilder()->create($tableName, function ($table) {
            foreach (static::$schema as $name => $type) {
                $table->{$type}($name)->nullable();
            }
        });

        $data = collect(Repository::fetchData(static::class));

        $schema = collect(str_getcsv($data->first()));

        $data->transform(function ($line) use ($schema) {
            return $schema->combine(str_getcsv($line));
        });

        $data->shift();

        foreach (array_chunk($data->toArray(), 100) ?? [] as $dataToInsert) {
            if (! empty($dataToInsert)) static::insert($dataToInsert);
        }
    }

    public static function resolveConnection($connection = null)
    {
        return static::$sqliteConnection;
    }

    protected static function setSqliteConnection($database)
    {
        static::$sqliteConnection = app(ConnectionFactory::class)->make([
            'driver' => 'sqlite',
            'database' => $database,
        ]);
    }

    public function usesTimestamps()
    {
        return false;
    }
}