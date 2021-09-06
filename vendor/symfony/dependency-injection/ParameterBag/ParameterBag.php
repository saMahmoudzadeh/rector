<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace RectorPrefix20210906\Symfony\Component\DependencyInjection\ParameterBag;

use RectorPrefix20210906\Symfony\Component\DependencyInjection\Exception\ParameterCircularReferenceException;
use RectorPrefix20210906\Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use RectorPrefix20210906\Symfony\Component\DependencyInjection\Exception\RuntimeException;
/**
 * Holds parameters.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class ParameterBag implements \RectorPrefix20210906\Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface
{
    protected $parameters = [];
    protected $resolved = \false;
    public function __construct(array $parameters = [])
    {
        $this->add($parameters);
    }
    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $this->parameters = [];
    }
    /**
     * {@inheritdoc}
     * @param mixed[] $parameters
     */
    public function add($parameters)
    {
        foreach ($parameters as $key => $value) {
            $this->set($key, $value);
        }
    }
    /**
     * {@inheritdoc}
     */
    public function all()
    {
        return $this->parameters;
    }
    /**
     * {@inheritdoc}
     * @param string $name
     */
    public function get($name)
    {
        if (!\array_key_exists($name, $this->parameters)) {
            if (!$name) {
                throw new \RectorPrefix20210906\Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException($name);
            }
            $alternatives = [];
            foreach ($this->parameters as $key => $parameterValue) {
                $lev = \levenshtein($name, $key);
                if ($lev <= \strlen($name) / 3 || \strpos($key, $name) !== \false) {
                    $alternatives[] = $key;
                }
            }
            $nonNestedAlternative = null;
            if (!\count($alternatives) && \strpos($name, '.') !== \false) {
                $namePartsLength = \array_map('strlen', \explode('.', $name));
                $key = \substr($name, 0, -1 * (1 + \array_pop($namePartsLength)));
                while (\count($namePartsLength)) {
                    if ($this->has($key)) {
                        if (\is_array($this->get($key))) {
                            $nonNestedAlternative = $key;
                        }
                        break;
                    }
                    $key = \substr($key, 0, -1 * (1 + \array_pop($namePartsLength)));
                }
            }
            throw new \RectorPrefix20210906\Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException($name, null, null, null, $alternatives, $nonNestedAlternative);
        }
        return $this->parameters[$name];
    }
    /**
     * {@inheritdoc}
     * @param string $name
     */
    public function set($name, $value)
    {
        $this->parameters[$name] = $value;
    }
    /**
     * {@inheritdoc}
     * @param string $name
     */
    public function has($name)
    {
        return \array_key_exists((string) $name, $this->parameters);
    }
    /**
     * {@inheritdoc}
     * @param string $name
     */
    public function remove($name)
    {
        unset($this->parameters[$name]);
    }
    /**
     * {@inheritdoc}
     */
    public function resolve()
    {
        if ($this->resolved) {
            return;
        }
        $parameters = [];
        foreach ($this->parameters as $key => $value) {
            try {
                $value = $this->resolveValue($value);
                $parameters[$key] = $this->unescapeValue($value);
            } catch (\RectorPrefix20210906\Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException $e) {
                $e->setSourceKey($key);
                throw $e;
            }
        }
        $this->parameters = $parameters;
        $this->resolved = \true;
    }
    /**
     * Replaces parameter placeholders (%name%) by their values.
     *
     * @param mixed $value     A value
     * @param array $resolving An array of keys that are being resolved (used internally to detect circular references)
     *
     * @return mixed The resolved value
     *
     * @throws ParameterNotFoundException          if a placeholder references a parameter that does not exist
     * @throws ParameterCircularReferenceException if a circular reference if detected
     * @throws RuntimeException                    when a given parameter has a type problem
     */
    public function resolveValue($value, $resolving = [])
    {
        if (\is_array($value)) {
            $args = [];
            foreach ($value as $k => $v) {
                $args[\is_string($k) ? $this->resolveValue($k, $resolving) : $k] = $this->resolveValue($v, $resolving);
            }
            return $args;
        }
        if (!\is_string($value) || 2 > \strlen($value)) {
            return $value;
        }
        return $this->resolveString($value, $resolving);
    }
    /**
     * Resolves parameters inside a string.
     *
     * @param array $resolving An array of keys that are being resolved (used internally to detect circular references)
     *
     * @return mixed The resolved string
     *
     * @throws ParameterNotFoundException          if a placeholder references a parameter that does not exist
     * @throws ParameterCircularReferenceException if a circular reference if detected
     * @throws RuntimeException                    when a given parameter has a type problem
     * @param string $value
     */
    public function resolveString($value, $resolving = [])
    {
        // we do this to deal with non string values (Boolean, integer, ...)
        // as the preg_replace_callback throw an exception when trying
        // a non-string in a parameter value
        if (\preg_match('/^%([^%\\s]+)%$/', $value, $match)) {
            $key = $match[1];
            if (isset($resolving[$key])) {
                throw new \RectorPrefix20210906\Symfony\Component\DependencyInjection\Exception\ParameterCircularReferenceException(\array_keys($resolving));
            }
            $resolving[$key] = \true;
            return $this->resolved ? $this->get($key) : $this->resolveValue($this->get($key), $resolving);
        }
        return \preg_replace_callback('/%%|%([^%\\s]+)%/', function ($match) use($resolving, $value) {
            // skip %%
            if (!isset($match[1])) {
                return '%%';
            }
            $key = $match[1];
            if (isset($resolving[$key])) {
                throw new \RectorPrefix20210906\Symfony\Component\DependencyInjection\Exception\ParameterCircularReferenceException(\array_keys($resolving));
            }
            $resolved = $this->get($key);
            if (!\is_string($resolved) && !\is_numeric($resolved)) {
                throw new \RectorPrefix20210906\Symfony\Component\DependencyInjection\Exception\RuntimeException(\sprintf('A string value must be composed of strings and/or numbers, but found parameter "%s" of type "%s" inside string value "%s".', $key, \get_debug_type($resolved), $value));
            }
            $resolved = (string) $resolved;
            $resolving[$key] = \true;
            return $this->isResolved() ? $resolved : $this->resolveString($resolved, $resolving);
        }, $value);
    }
    public function isResolved()
    {
        return $this->resolved;
    }
    /**
     * {@inheritdoc}
     */
    public function escapeValue($value)
    {
        if (\is_string($value)) {
            return \str_replace('%', '%%', $value);
        }
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->escapeValue($v);
            }
            return $result;
        }
        return $value;
    }
    /**
     * {@inheritdoc}
     */
    public function unescapeValue($value)
    {
        if (\is_string($value)) {
            return \str_replace('%%', '%', $value);
        }
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->unescapeValue($v);
            }
            return $result;
        }
        return $value;
    }
}
