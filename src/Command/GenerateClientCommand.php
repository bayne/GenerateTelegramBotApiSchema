<?php

namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateClientCommand extends ContainerAwareCommand
{

    public const BASE_NAMESPACE = 'Bayne\\Telegram\\Bot';
    public const BASE_NAMESPACE_TYPES = self::BASE_NAMESPACE . '\\Type';

    protected function configure(): void
    {
        $this->setName('generate:client');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $buildDirSource = $this->getContainer()->getParameter('kernel.root_dir') . '/../build/source/';
        $jsonPath = $buildDirSource . 'schema.json';
        $schema = file_get_contents($jsonPath);
        $schema = json_decode($schema, true);

        $buildDir = $this->getContainer()->getParameter('kernel.root_dir') . '/../build';
        $baseDir = $buildDir . '/' . str_replace('\\', '/', self::BASE_NAMESPACE);
        $baseDirTypes = $buildDir . '/' . str_replace('\\', '/', self::BASE_NAMESPACE_TYPES);

        if (
            (false === is_dir($baseDirTypes))
            && !mkdir($concurrentDirectory = $baseDirTypes, 0777, true)
            && !is_dir($concurrentDirectory)
        ) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

        $this->generate(
            self::BASE_NAMESPACE,
            self::BASE_NAMESPACE_TYPES,
            "{$baseDirTypes}/AbstractInlineQueryResult.php",
            'AbstractInlineQueryResult.php.twig'
        );

        $this->generate(
            self::BASE_NAMESPACE,
            self::BASE_NAMESPACE_TYPES,
            "{$baseDirTypes}/AbstractInputMessageContent.php",
            'AbstractInputMessageContent.php.twig'
        );

        $this->generate(
            self::BASE_NAMESPACE,
            self::BASE_NAMESPACE_TYPES,
            "{$baseDirTypes}/InputFileInterface.php",
            'InputFileInterface.php.twig'
        );

        $this->generate(
            self::BASE_NAMESPACE,
            self::BASE_NAMESPACE_TYPES,
            "{$baseDirTypes}/AbstractType.php",
            'AbstractType.php.twig'
        );

        foreach ($schema['types'] as $type) {
            $this->generate(
                self::BASE_NAMESPACE,
                self::BASE_NAMESPACE_TYPES,
                "{$baseDirTypes}/{$type['name']}.php",
                'Type.php.twig',
                ['type' => $type]
            );
        }

        $this->generate(
            self::BASE_NAMESPACE,
            self::BASE_NAMESPACE_TYPES,
            "{$baseDir}/ClientInterface.php",
            'ClientInterface.php.twig',
            ['schema' => $schema]
        );
        $this->generate(
            self::BASE_NAMESPACE,
            self::BASE_NAMESPACE_TYPES,
            "{$baseDir}/TypedClientInterface.php",
            'TypedClientInterface.php.twig',
            ['schema' => $schema]
        );
    }

    private function generate(string $baseNamespace, string $typesNamespace, string $path, string $template, array $data = []): void
    {
        $data['__BASE_NAMESPACE'] = $baseNamespace;
        $data['__TYPES_NAMESPACE'] = $typesNamespace;

        if (file_put_contents($path, $this->render($template, $data)) === false) {
            throw new \RuntimeException(sprintf('Failed write to file %s', $path));
        }
    }

    private function render(string $template, array $data = []): string
    {
        return $this->getContainer()->get('twig')->render($template, $data);
    }
}