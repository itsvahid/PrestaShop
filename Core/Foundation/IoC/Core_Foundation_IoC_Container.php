<?php

class Core_Foundation_IoC_Container
{
    private $bindings = array();
    private $instances = array();
    private $namespaceAliases = array();

    public function knows($serviceName)
    {
        return array_key_exists($serviceName, $this->bindings);
    }

    private function knowsNamespaceAlias($alias)
    {
        return array_key_exists($alias, $this->namespaceAliases);
    }

    public function bind($serviceName, $constructor, $shared = false)
    {
        if ($this->knows($serviceName)) {
            throw new Core_Foundation_IoC_Exception(
                sprintf('Cannot bind `%s` again. A service name can only be bound once.', $serviceName)
            );
        }

        $this->bindings[$serviceName] = array(
            'constructor' => $constructor,
            'shared' => $shared
        );

        return $this;
    }

    public function aliasNamespace($alias, $namespacePrefix)
    {
        if ($this->knowsNamespaceAlias($alias)) {
            throw new Core_Foundation_IoC_Exception(
                sprintf(
                    'Namespace alias `%1$s` already exists and points to `%2$s`',
                    $alias, $this->namespaceAliases[$alias]
                )
            );
        }

        $this->namespaceAliases[$alias] = $namespacePrefix;
        return $this;
    }

    public function resolveClassName($className)
    {
        $colonPos = strpos($className, ':');
        if (0 !== $colonPos) {
            $alias = substr($className, 0, $colonPos);
            if ($this->knowsNamespaceAlias($alias)) {
                $class = ltrim(substr($className, $colonPos + 1), '\\');
                return $this->namespaceAliases[$alias] . '\\' . $class;
            }
        }

        return $className;
    }

    private function makeInstanceFromClassName($className, array $alreadySeen)
    {
        $className = $this->resolveClassName($className);

        try {
            $refl = new ReflectionClass($className);
        } catch (ReflectionException $re) {
            throw new Core_Foundation_IoC_Exception(sprintf('This doesn\'t seem to be a class name: `%s`.', $className));
        }

        $args = array();

        if ($refl->isAbstract()) {
            throw new Core_Foundation_IoC_Exception(sprintf('Cannot build abstract class: `%s`.', $className));
        }

        $classConstructor = $refl->getConstructor();

        if ($classConstructor) {
            foreach ($classConstructor->getParameters() as $param) {
                $paramClass = $param->getClass();
                if ($paramClass) {
                    $args[] = $this->doMake($param->getClass()->getName(), $alreadySeen);
                } else if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    throw new Core_Foundation_IoC_Exception(sprintf('Cannot build a `%s`.', $className));
                }
            }
        }

        return $refl->newInstanceArgs($args);
    }

    private function doMake($serviceName, array $alreadySeen = array())
    {
        if (array_key_exists($serviceName, $alreadySeen)) {
            throw new Core_Foundation_IoC_Exception(sprintf(
                'Cyclic dependency detected while building `%s`.',
                $serviceName
            ));
        }

        $alreadySeen[$serviceName] = true;

        if (!$this->knows($serviceName)) {
            $this->bind($serviceName, $serviceName);
        }

        $binding = $this->bindings[$serviceName];

        if ($binding['shared'] && array_key_exists($serviceName, $this->instances)) {
            return $this->instances[$serviceName];
        } else {
            $constructor = $binding['constructor'];

            if (is_callable($constructor)) {
                $service = call_user_func($constructor);
            } else if (!is_string($constructor)) {
                // user already provided the value, no need to construct it.
                $service = $constructor;
            } else {
                // assume the $constructor is a class name
                $service = $this->makeInstanceFromClassName($constructor, $alreadySeen);
            }

            if ($binding['shared']) {
                $this->instances[$serviceName] = $service;
            }

            return $service;
        }
    }

    public function make($serviceName)
    {
        return $this->doMake($serviceName, array());
    }
}
