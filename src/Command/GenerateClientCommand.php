<?php

namespace App\Command;


use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateClientCommand extends ContainerAwareCommand
{
    /**
     * 
     */
    protected function configure()
    {
        $this
            ->setName('generate:client')
            ->addArgument('schema', InputArgument::REQUIRED)
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $schemaFile = $input->getArgument('schema');
        $schema = file_get_contents($schemaFile);
        $schema = json_decode($schema, true);
        
        $buildDir = $this->getContainer()->getParameter('kernel.root_dir').'/../build';

        if (false === is_dir($buildDir.'/Bayne/Telegram/Bot/Object')) {
            mkdir($buildDir.'/Bayne/Telegram/Bot/Object', 0777, true);
        }


        file_put_contents(
            $buildDir.'/Bayne/Telegram/Bot/Object/AbstractInlineQueryResult.php',
            $this->getContainer()->get('twig')->render(
                'AbstractInlineQueryResult.php.twig'
            )
        );
        
        file_put_contents(
            $buildDir.'/Bayne/Telegram/Bot/Object/AbstractInputMessageContent.php',
            $this->getContainer()->get('twig')->render(
                'AbstractInputMessageContent.php.twig'
            )
        );

        file_put_contents(
            $buildDir.'/Bayne/Telegram/Bot/Object/InputFileInterface.php',
            $this->getContainer()->get('twig')->render(
                'InputFileInterface.php.twig'
            )
        );
        
        file_put_contents(
            $buildDir.'/Bayne/Telegram/Bot/Object/AbstractObject.php',
            $this->getContainer()->get('twig')->render(
                'AbstractObject.php.twig'
            )
        );

        foreach ($schema['objects'] as $object) {
            $contents = $this->getContainer()->get('twig')->render(
                'Object.php.twig',
                [
                    'object' => $object
                ]
            );

            file_put_contents($buildDir.'/Bayne/Telegram/Bot/Object/'.$object['name'].'.php', $contents);
        }

        $contents = $this->getContainer()->get('twig')->render(
            'ClientInterface.php.twig',
            [
                'schema' => $schema
            ]
        );

        file_put_contents($buildDir.'/Bayne/Telegram/Bot/ClientInterface.php', $contents);
        
    }
}