<?php

namespace Reaper\Config;

use Symfony\Component\Yaml\Yaml;

final class Config
{
    /** @var string[] */
    public array $include = [];
    
    /** @var string[] */
    public array $exclude = [];
    
    /** @var string[] */
    public array $entryFiles = [];
    
    /** @var string[] */
    public array $keepGlobs = [];
    
    /** @var string[] */
    public array $keepPatterns = [];
    
    /** @var string[] */
    public array $riskyPatterns = [];
    
    /** @var int */
    public int $deleteThreshold = 5;
    
    /** @var string */
    public string $outputDir = 'out';

    /**
     * Load configuration from a YAML file
     * @param string $path
     * @return self
     * @throws \RuntimeException if the file does not exist
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Config not found: $path");
        }
        $y = Yaml::parseFile($path);

        $c = new self();
        $c->include = $y['paths']['include'] ?? ['src/**'];
        $c->exclude = $y['paths']['exclude'] ?? ['vendor/**'];
        $c->entryFiles = $y['entry_points']['files'] ?? [];
        $c->keepGlobs = $y['rules']['keep_globs'] ?? [];
        $c->keepPatterns = $y['rules']['keep_patterns'] ?? [];
        $c->riskyPatterns = $y['risky_patterns'] ?? [];
        $c->deleteThreshold = (int) ($y['scoring']['delete_threshold'] ?? 5);
        $c->outputDir = $y['output_dir'] ?? 'out';
        return $c;
    }
}