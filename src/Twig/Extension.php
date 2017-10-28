<?php

namespace App\Twig;

use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

class Extension extends \Twig_Extension
{
    /**
     * @var CamelCaseToSnakeCaseNameConverter
     */
    private $converter;

    /**
     * Extension constructor.
     */
    public function __construct()
    {
        $this->converter = new CamelCaseToSnakeCaseNameConverter();
    }

    public function getFilters()
    {
        return [
            new \Twig_Filter('camelize', [$this, 'camelize']),
            new \Twig_Filter('paramDescription', [$this, 'paramDescription']),
            new \Twig_Filter('sortByOptional', [$this, 'sortByOptional'])
        ];
    }

    public function camelize($string)
    {
        return $this->converter->denormalize($string);
    }

    public function paramDescription($description, $indentation)
    {
        $paragraphs = explode("\n", $description);
        $words = [];
        foreach ($paragraphs as $paragraph) {
            $words = array_merge($words, explode(' ', $paragraph));
        }

        $newDescription = '';
        $characterCount = $indentation;
        foreach ($words as $word) {
            $characterCount += strlen($word);
            if ($characterCount > 100) {
                $newDescription .= "\n     * ".$word.' ';
                $characterCount = $indentation;
            } else {
                $newDescription .= $word.' ';
            }
        }
        
        return $newDescription;
        
//        return implode("\n     * ".str_repeat(' ', $indentation), str_split(, 100 - $indentation));
    }
    
    public function sortByOptional($parameters)
    {
        usort($parameters, function ($a, $b) {
            return $b['required'] - $a['required'];
        });
        
        return $parameters;
    }

}