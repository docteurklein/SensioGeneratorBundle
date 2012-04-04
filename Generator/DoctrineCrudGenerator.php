<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sensio\Bundle\GeneratorBundle\Generator;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Generates a CRUD controller.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class DoctrineCrudGenerator extends Generator
{
    private $filesystem;
    private $kernel;
    private $routePrefix;
    private $routeNamePrefix;
    private $bundle;
    private $entity;
    private $metadata;
    private $format;
    private $actions;

    /**
     * Constructor.
     *
     * @param Filesystem $filesystem A Filesystem instance
     * @param Kernel $kernel the kernel used to locate resources
     * @param string $subDir Path to the controller directory
     */
    public function __construct(Filesystem $filesystem, Kernel $kernel, $skeletonTheme, $defaultSkeletonTheme, $subDir)
    {
        $this->filesystem  = $filesystem;
        $this->kernel = $kernel;
        $this->skeletonTheme = $skeletonTheme;
        $this->defaultSkeletonTheme = $defaultSkeletonTheme;
        $this->subDir = $subDir;
    }

    protected function getEntitySingular()
    {
        return strtolower($this->entity);
    }

    protected function getEntityPlural()
    {
        return strtolower($this->entity).'s';
    }

    protected function locateResource($name)
    {
        $dir = sprintf('@SensioGeneratorBundle/Resources/skeleton/crud/%s', $this->skeletonTheme);
        $defaultDir = sprintf('@SensioGeneratorBundle/Resources/skeleton/crud/%s', $this->defaultSkeletonTheme);
        try {
            return array(
                array(
                    $this->kernel->locateResource($dir),
                    $this->kernel->locateResource($defaultDir),
                ),
                $name
            );
        }
        catch(\InvalidArgumentException $e) {
            var_dump($e->getMessage());
            return array($this->kernel->locateResource($dir), $name);
        }
    }

    /**
     * Generate the CRUD controller.
     *
     * @param BundleInterface $bundle A bundle object
     * @param string $entity The entity relative class name
     * @param ClassMetadataInfo $metadata The entity class metadata
     * @param string $format The configuration format (xml, yaml, annotation)
     * @param string $routePrefix The route name prefix
     * @param array $needWriteActions Wether or not to generate write actions
     *
     * @throws \RuntimeException
     */
    public function generate(BundleInterface $bundle, $entity, ClassMetadataInfo $metadata, $format, $routePrefix, $needWriteActions)
    {
        $this->routePrefix = $routePrefix;
        $this->routeNamePrefix = str_replace('/', '_', $routePrefix);
        $this->actions = $needWriteActions ? array('list', 'filter', 'show', 'new', 'edit', 'delete') : array('list', 'filter', 'show');

        if (count($metadata->identifier) > 1) {
            throw new \RuntimeException('The CRUD generator does not support entity classes with multiple primary keys.');
        }

        if (!in_array('id', $metadata->identifier)) {
            throw new \RuntimeException('The CRUD generator expects the entity object has a primary key field named "id" with a getId() method.');
        }

        $this->entity   = $entity;
        $this->bundle   = $bundle;
        $this->metadata = $metadata;
        $this->setFormat($format);

        $this->generateControllerClass();

        $dir = sprintf('%s/Resources/views/%s/%s', $this->bundle->getPath(), $this->subDir, str_replace('\\', '/', $this->entity));

        if (!file_exists($dir)) {
            $this->filesystem->mkdir($dir, 0777);
        }

        $this->generateListView($dir);

        if (in_array('filter', $this->actions)) {
            $this->generateFilterView($dir);
        }

        if (in_array('show', $this->actions)) {
            $this->generateShowView($dir);
        }

        if (in_array('new', $this->actions)) {
            $this->generateNewView($dir);
        }

        if (in_array('edit', $this->actions)) {
            $this->generateEditView($dir);
        }

        $this->generateTestClass();
        $this->generateConfiguration();
    }

    /**
     * Sets the configuration format.
     *
     * @param string $format The configuration format
     */
    private function setFormat($format)
    {
        switch ($format) {
            case 'yml':
            case 'xml':
            case 'php':
            case 'annotation':
                $this->format = $format;
                break;
            default:
                $this->format = 'yml';
                break;
        }
    }

    /**
     * Generates the routing configuration.
     *
     */
    private function generateConfiguration()
    {
        if (!in_array($this->format, array('yml', 'xml', 'php'))) {
            return;
        }

        $target = sprintf(
            '%s/Resources/config/routing/%s.%s',
            $this->bundle->getPath(),
            strtolower(str_replace('\\', '_', $this->entity)),
            $this->format
        );

        $this->renderThemeFile($this->locateResource('config/routing.'.$this->format), $target, array(
            'actions'           => $this->actions,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'bundle'            => $this->bundle->getName(),
            'entity'            => $this->entity,
            'subDir'            => $this->subDir,
        ));
    }

    /**
     * Generates the controller class only.
     *
     */
    private function generateControllerClass()
    {
        $dir = $this->bundle->getPath();

        $parts = explode('\\', $this->entity);
        $entityClass = array_pop($parts);
        $entityNamespace = implode('\\', $parts);

        $target = sprintf(
            '%s/Controller/%s/%s/%sController.php',
            $dir,
            $this->subDir,
            str_replace('\\', '/', $entityNamespace),
            $entityClass
        );

        if (file_exists($target)) {
            throw new \RuntimeException('Unable to generate the controller as it already exists.');
        }

        $this->renderThemeFile($this->locateResource('controller.php'), $target, array(
            'actions'           => $this->actions,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'bundle'            => $this->bundle->getName(),
            'entity'            => $this->entity,
            'entity_class'      => $entityClass,
            'namespace'         => $this->bundle->getNamespace(),
            'entity_namespace'  => $entityNamespace,
            'subDir'            => $this->subDir,
            'format'            => $this->format,
        ));
    }

    /**
     * Generates the functional test class only.
     *
     */
    private function generateTestClass()
    {
        $parts = explode('\\', $this->entity);
        $entityClass = array_pop($parts);
        $entityNamespace = implode('\\', $parts);

        $dir    = $this->bundle->getPath() .'/Tests/Controller';
        $target = $dir .'/'. str_replace('\\', '/', $entityNamespace).'/'. $entityClass .'ControllerTest.php';

        $this->renderThemeFile($this->locateResource('tests/test.php'), $target, array(
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'entity'            => $this->entity,
            'entity_class'      => $entityClass,
            'namespace'         => $this->bundle->getNamespace(),
            'entity_namespace'  => $entityNamespace,
            'actions'           => $this->actions,
        ));
    }

    /**
     * Generates the list.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateListView($dir)
    {
        $this->renderThemeFile($this->locateResource('views/list.html.twig'), $dir.'/list.html.twig', array(
            'subDir'            => $this->subDir,
            'entity'            => $this->entity,
            'entity_singular'   => $this->getEntitySingular(),
            'entity_plural'     => $this->getEntityPlural(),
            'fields'            => $this->metadata->fieldMappings,
            'actions'           => $this->actions,
            'record_actions'    => $this->getRecordActions(),
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'filter_template_name' => sprintf('%s:%s/%s:%s', $this->bundle->getName(), $this->subDir, str_replace('\\', '/', $this->entity), 'filter.html.twig'),
        ));
    }

    /**
     * Generates the filter.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateFilterView($dir)
    {
        $this->renderThemeFile($this->locateResource('views/filter.html.twig'), $dir.'/filter.html.twig', array(
            'bundle'            => $this->bundle->getName(),
            'subDir'            => $this->subDir,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'entity'            => $this->entity,
            'entity_singular'   => $this->getEntitySingular(),
            'entity_plural'     => $this->getEntityPlural(),
            'actions'           => $this->actions,
        ));
    }

    /**
    * Generates the show.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateShowView($dir)
    {
        $this->renderThemeFile($this->locateResource('views/show.html.twig'), $dir.'/show.html.twig', array(
            'entity'            => $this->entity,
            'entity_singular'   => $this->getEntitySingular(),
            'entity_plural'     => $this->getEntityPlural(),
            'fields'            => $this->metadata->fieldMappings,
            'actions'           => $this->actions,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
        ));
    }

    /**
     * Generates the new.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateNewView($dir)
    {
        $this->renderThemeFile($this->locateResource('views/new.html.twig'), $dir.'/new.html.twig', array(
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'entity'            => $this->entity,
            'entity_singular'   => $this->getEntitySingular(),
            'entity_plural'     => $this->getEntityPlural(),
            'actions'           => $this->actions,
        ));
    }

    /**
     * Generates the edit.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    private function generateEditView($dir)
    {
        $this->renderThemeFile($this->locateResource('views/edit.html.twig'), $dir.'/edit.html.twig', array(
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'entity'            => $this->entity,
            'entity_singular'   => $this->getEntitySingular(),
            'entity_plural'     => $this->getEntityPlural(),
            'actions'           => $this->actions,
        ));
    }

    /**
     * Returns an array of record actions to generate (edit, show).
     *
     * @return array
     */
    private function getRecordActions()
    {
        return array_filter($this->actions, function($item) {
            return in_array($item, array('show', 'edit'));
        });
    }
}
