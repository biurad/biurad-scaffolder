<?php

declare(strict_types=1);

/*
 * This file is part of BiuradPHP opensource projects.
 *
 * PHP version 7.2 and above required
 *
 * @author    Divine Niiquaye Ibok <divineibok@gmail.com>
 * @copyright 2019 Biurad Group (https://biurad.com/)
 * @license   https://opensource.org/licenses/BSD-3-Clause License
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BiuradPHP\Scaffold;

use BiuradPHP\Scaffold\Config\MakerConfig;
use BiuradPHP\Scaffold\Exceptions\RuntimeCommandException;
use Exception;
use LogicException;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;

/**
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 * @author Ryan Weaver <weaverryan@gmail.com>
 *
 * @method string getNamespace(string $element)
 * @method string getSuffix(string $element)
 */
class Generator
{
    private $config;

    private $fileManager;

    private $pendingOperations = [];

    private $namespacePrefix;

    public function __construct(MakerConfig $config, FileManager $fileManager)
    {
        $this->config          = $config;
        $this->fileManager     = $fileManager;
        $this->namespacePrefix = $this->config->baseNamespace();
    }

    public function __call($name, $arguments)
    {
        switch ($name) {
            case 'getNamespace':
                return \rtrim($this->config->getOption($arguments[0], 'namespace'), '\\') . '\\';

            case 'getSuffix':
                return \rtrim($this->config->getOption($arguments[0], 'postfix'), '\\');
        }
    }

    /**
     * Generate a new file for a class from a template.
     *
     * @param ClassNameDetails|string $classNameDetails The fully-qualified class name or details
     * @param null|string             $templateName     Template name in Resources/skeleton to use
     * @param array                   $variables        Array of variables to pass to the template
     *
     * @throws Exception
     *
     * @return string The path where the file will be created
     */
    public function generateClass($classNameDetails, ?string $templateName, array $variables = []): string
    {
        $className = $classNameDetails;

        if ($classNameDetails instanceof ClassNameDetails) {
            $className    = $classNameDetails->getFullName();
            $templateName = $this->writeDeclaration(new PhpFile(), $classNameDetails);
        }

        $targetPath = $this->fileManager->getRelativePathForFutureClass($className);

        if (null === $targetPath) {
            throw new LogicException(
                \sprintf(
                    'Could not determine where to locate the new class "%s",'
                    . ' maybe try with a full namespace like "\\My\\Full\\Namespace\\%s"',
                    $className,
                    HelperUtil::getShortClassName($className)
                )
            );
        }

        $variables = \array_merge($variables, [
            'class_name' => HelperUtil::getShortClassName($className),
            'namespace'  => HelperUtil::getNamespace($className),
        ]);

        $this->addOperation($targetPath, $templateName, $variables);

        return $targetPath;
    }

    /**
     * Generate a normal file from a template.
     */
    public function generateFile(string $targetPath, string $templateName, array $variables): void
    {
        $this->addOperation($targetPath, $templateName, $variables);
    }

    public function dumpFile(string $targetPath, string $contents): void
    {
        $this->pendingOperations[$targetPath] = [
            'contents' => $contents,
        ];
    }

    public function getFileContentsForPendingOperation(string $targetPath): string
    {
        if (!isset($this->pendingOperations[$targetPath])) {
            throw new RuntimeCommandException(
                \sprintf('File "%s" is not in the Generator\'s pending operations', $targetPath)
            );
        }

        $templatePath = $this->pendingOperations[$targetPath]['template'];
        $parameters   = $this->pendingOperations[$targetPath]['variables'];

        if ($templatePath instanceof PhpFile) {
            return (new PsrPrinter())->printFile($templatePath);
        }

        $templateParameters = \array_merge($parameters, [
            'relative_path' => $this->fileManager->relativizePath($targetPath),
        ]);

        return $this->fileManager->parseTemplate($templatePath, $templateParameters);
    }

    /**
     * Creates a helper object to get data about a class name.
     *
     * Examples:
     *
     *      // App\Entity\FeaturedProduct
     *      $gen->createClassNameDetails('FeaturedProduct', 'Entity');
     *      $gen->createClassNameDetails('featured product', 'Entity');
     *
     *      // App\Controller\FooController
     *      $gen->createClassNameDetails('foo', 'Controller', 'Controller');
     *
     *      // App\Controller\Admin\FooController
     *      $gen->createClassNameDetails('Foo\\Admin', 'Controller', 'Controller');
     *
     *      // App\Controller\Security\Voter\CoolController
     *      $gen->createClassNameDetails('Cool', 'Security\Voter', 'Voter');
     *
     *      // Full class names can also be passed. Imagine the user has an autoload
     *      // rule where Cool\Stuff lives in a "lib/" directory
     *      // Cool\Stuff\BalloonController
     *      $gen->createClassNameDetails('Cool\\Stuff\\Balloon', 'Controller', 'Controller');
     *
     * @param string $name            The short "name" that will be turned into the class name
     * @param string $namespacePrefix Recommended namespace where this class live, but *without* the "App\\" part
     * @param string $suffix          Optional suffix to guarantee is on the end of the class
     */
    public function createClassNameDetails(
        string $name,
        string $namespacePrefix,
        string $suffix = '',
        string $validationErrorMessage = ''
    ): ClassNameDetails {
        $fullNamespacePrefix = $this->namespacePrefix . '\\' . $namespacePrefix;

        if ('\\' === $name[0]) {
            // class is already "absolute" - leave it alone (but strip opening \)
            $className = \substr($name, 1);
        } else {
            $className = \rtrim($fullNamespacePrefix, '\\') . '\\' . HelperUtil::asClassName($name, $suffix);
        }

        Validator::validateClassName($className, $validationErrorMessage);

        // if this is a custom class, we may be completely different than the namespace prefix
        // the best way can do, is find the PSR4 prefix and use that
        if (0 !== \strpos($className, $fullNamespacePrefix)) {
            $fullNamespacePrefix = $this->fileManager->getNamespacePrefixForClass($className);
        }

        return new ClassNameDetails($className, $fullNamespacePrefix, $suffix);
    }

    public function getRootDirectory(): string
    {
        return $this->fileManager->getRootDirectory();
    }

    public function hasPendingOperations(): bool
    {
        return !empty($this->pendingOperations);
    }

    /**
     * Actually writes and file changes that are pending.
     */
    public function writeChanges(): void
    {
        foreach ($this->pendingOperations as $targetPath => $templateData) {
            if (isset($templateData['contents'])) {
                $this->fileManager->dumpFile(
                    $targetPath,
                    $templateData['contents'],
                    $templateData['variables']['class_name'] ?? null
                );

                continue;
            }

            $this->fileManager->dumpFile(
                $targetPath,
                $this->getFileContentsForPendingOperation($targetPath, $templateData),
                $templateData['variables']['class_name'] ?? null
            );
        }

        $this->pendingOperations = [];
    }

    public function getRootNamespace(): string
    {
        return $this->namespacePrefix;
    }

    protected function writeDeclaration(PhpFile $phpFile, ClassNameDetails $classNameDetails): PhpFile
    {
        $phpFile->setStrictTypes(); // adds declare(strict_types=1)
        $phpFile->setComment(\join("\n", $this->config->headerLines()));

        $namespace = $phpFile->addNamespace($classNameDetails->getFullNamespace()); // Set the namespace.
        $classNameDetails->generate($namespace);

        return $phpFile;
    }

    private function addOperation(string $targetPath, $templateName, array $variables): void
    {
        if ($this->fileManager->fileExists($targetPath)) {
            throw new RuntimeCommandException(
                \sprintf(
                    'The file "%s" can\'t be generated because it already exists.',
                    $this->fileManager->relativizePath($targetPath)
                )
            );
        }

        $variables['relative_path'] = $this->fileManager->relativizePath($targetPath);

        $templatePath = $templateName;

        if (\is_string($templatePath) && !\file_exists($templatePath)) {
            $templatePath = __DIR__ . '/Resources/skeleton/' . $templateName;

            if (!\file_exists($templatePath)) {
                throw new Exception(\sprintf('Cannot find template "%s"', $templateName));
            }
        }

        $this->pendingOperations[$targetPath] = [
            'template'  => $templatePath,
            'variables' => $variables,
        ];
    }
}
