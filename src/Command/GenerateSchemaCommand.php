<?php

namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

class GenerateSchemaCommand extends ContainerAwareCommand
{
    const URL = 'https://core.telegram.org/bots/api';

    /**
     * @var array
     */
    private $schema;

    protected function configure()
    {
        $this->setName('generate:schema');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!file_exists('schema.html')) {
            $contents = file_get_contents(self::URL);
            file_put_contents('schema.html', $contents);    
        }
        
        $html = file_get_contents('schema.html');
        
        
        $this->schema = [
            'objects' => [
                
            ],
            'methods' => [
                
            ]
        ];
        
        $crawler = new Crawler($html);
        $node = $crawler->filter('table.table');
        $methodNodes = [];
        $node->each(function (Crawler $tableNode, $i) use (&$methodNodes) {
            
            $methodOrObjectName = '';
            $descriptions = [];
            $tableNode->previousAll()->each(function (Crawler $node, $j) use (&$methodOrObjectName, &$methodNodes, $tableNode, &$descriptions) {
                if ($node->nodeName() === 'h4' && $methodOrObjectName == '') {
                    $methodOrObjectName = $node->text();
                    if (ctype_upper($methodOrObjectName[0])) {

                        $fields = [];
                        $tableNode->filter('tbody tr')->each(function (Crawler $rowNode, $rowNumber) use (&$fields) {
                            if ($rowNumber === 0) {
                                return;
                            }
                            
                            if (0 === strpos($rowNode->filter('td:nth-child(3)')->text(), 'Optional')) {
                                $required = false;
                            } else {
                                $required = true;
                            }
                            
                            $fields[] = [
                                'name' => $rowNode->filter('td:nth-child(1)')->text(),
                                'roughType' => $rowNode->filter('td:nth-child(2)')->text(),
                                'description' => $rowNode->filter('td:nth-child(3)')->text(),
                                'required' => $required,
                            ];
                        });

                        $this->schema['objects'][] = [
                            'name' => $methodOrObjectName,
                            'link' => self::URL.$node->filter('a')->attr('href'),
                            'fields' => $fields
                        ];


                    } else {
                        $methodNodes[$methodOrObjectName] = [
                            'tableNode' => $tableNode,
                            'descriptions' => array_merge([], $descriptions)
                        ];
                    }
                } else {
                    $descriptions[] = $node->html();
                }
            });
        });
        
        foreach ($this->schema['objects'] as &$object) {
            foreach ($object['fields'] as &$field) {
                $field['type'] = $this->parseType($field['roughType']);
                if (false !== strpos('Required', $field['description'])) {
                    $field['required'] = true;
                }
                $field['is_object'] = $this->isObject($field['type']);
                $field['is_multiple_types'] = count(explode('|', $field['type'])) > 1;
                $field['is_collection'] = strpos($field['type'], '[]') !== false;
                unset($field['roughType']);
            }
            $object['parent'] = $this->getParent($object['name']);
            
        }
        
        foreach ($methodNodes as $methodName => $method) {
            $tableNode = $method['tableNode'];
            $descriptions = $method['descriptions'];
            $parameters = [];
            $tableNode->filter('tbody tr')->each(function (Crawler $rowNode, $rowNumber) use (&$parameters) {
                if ($rowNumber === 0) {
                    return;
                }
                
                $type = $this->parseType($rowNode->filter('td:nth-child(2)')->text());
                $description = $rowNode->filter('td:nth-child(4)')->text();
                
                $required = (false !== strpos($description, 'Required'));

                if ($required === false) {
                    $required = $this->parseRequired($rowNode->filter('td:nth-child(3)')->text());
                }

                $parameters[] = [
                    'name' => $rowNode->filter('td:nth-child(1)')->text(),
                    'type' => $type,
                    'is_multiple_types' => count(explode('|', $type)) > 1,
                    'is_collection' => strpos($type, '[]') !== false,
                    'is_object' => $this->isObject($type),
                    'required' => $required,
                    'description' => $description,
                ];
            });

            $description = implode("\n", $descriptions);

            $this->schema['methods'][] = [
                'name' => $methodName,
                'parameters' => $parameters,
                'description' => $description,
                'link' => self::URL.$node->filter('a')->attr('href'),
            ];
            
        }
        
        echo json_encode($this->schema, JSON_PRETTY_PRINT);
    }
    
    private function parseRequired($text)
    {
        if ($text === 'Yes') {
            $text = true;
        } elseif ($text === 'True') {
            $text = true;
        } elseif ($text === 'Optional') {
            $text = false;
        } elseif ($text === 'No') {
            $text = false;
        } else {
            throw new \ParseError('Unexpected required: '.$text);
        }       
        return $text;
    }
    
    private function parseType($text)
    {
        if (false !== strpos($text, ' or ')) {
            $pieces = explode(' or ', $text);
            $types = [];
            foreach ($pieces as $piece) {
                $types[] = $this->parseType($piece);
            }
            return implode('|', $types);
        }

        if (false !== strpos($text, 'Array of ')) {
            list($_, $text) = explode(' of ', $text);
            $type = $this->parseType($text);
            return $type.'[]';
        }
        
        if ($text === 'Float' || $text === 'Float number') {
            return 'float';
        } elseif ($text === 'Integer') {
            return 'int';
        } elseif ($text === 'True') {
            return 'bool';
        } elseif ($text === 'CallbackGame') {
            return 'array';
        } elseif ($text === 'Array') {
            return 'array';
        } elseif ($text === 'Integer or String') {
            return 'string';
        } elseif ($text === 'Boolean') {
            return 'bool';
        } elseif ($text === 'String') {
            return 'string';
        } elseif ($text === 'Array of String') {
            return 'string[]';
        } elseif ($text === 'InputFile') {
            return 'Object\InputFileInterface';
        } elseif ($text === 'InlineQueryResult') {
            return 'Object\AbstractInlineQueryResult';
        } elseif ($text === 'InputMessageContent') {
            return 'Object\AbstractInputMessageContent';
        } elseif ($this->isObject($text)) {
            return 'Object\\'.$text;
        } else {
            throw new \ParseError('Unexpected type: '.$text);
        }
    }

    /**
     * @param $text
     * @return bool
     */
    private function isObject($text)
    {
        foreach ($this->schema['objects'] as $object) {
            if ($object['name'] === $text) {
                return true;
            }
        }
        try {
            $this->getParent($text);
            return true;
        } catch (\ParseError $e) {
            return false;
        }
    }
    
    private function getParent($type)
    {
        if (0 === strpos($type, 'InlineQueryResult')) {
            return 'Object\AbstractInlineQueryResult';
        }
        
        if (0 === strpos($type, 'Input') && false !== strpos($type, 'MessageContent')) {
            return 'Object\AbstractInputMessageContent';
        }
        
        if (ctype_upper($type[0])) {
            return 'Object\AbstractObject';
        }
        
        throw new \ParseError('Cannot determine parent of type: '.$type);
    }

    private function getReturnType($description)
    {
        $returnsDescription = substr($description, stripos($description, 'Return'));
        $onSuccessDescription = substr($description, stripos($description, 'On success'));
        if ($onSuccessDescription < $returnsDescription) {
            $returnsDescription = $onSuccessDescription;
        }
        $crawler = new Crawler($returnsDescription);
        $returnObjects = [];
        try {

            $crawler->filter('a')->each(function (Crawler $node) use (&$returnObjects) {
                if (strpos($node->attr('href'), '#') === 0) {
                    try {
                        $returnObjects[] = $this->parseType($node->text());
                    } catch (\ParseError $e) {

                    }
                }
            });

            $crawler->filter('em')->each(function (Crawler $node) use (&$returnObjects) {
                try {
                    $returnObjects[] = $this->parseType($node->text());
                } catch (\ParseError $e) {

                }
            });
        } catch (\ParseError $e) {
            throw new \ParseError($e.' Could not parse description for return type: '.$description);
        }

        return implode('|', $returnObjects);
    }

}