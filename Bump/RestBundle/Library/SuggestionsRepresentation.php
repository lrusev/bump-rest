<?php
namespace Bump\RestBundle\Library;

use Hateoas\Configuration\Annotation as Hateoas;
use JMS\Serializer\Annotation as Serializer;

class SuggestionsRepresentation
{

     /**
     * @var array
     *
     * @Serializer\Expose
     * @Serializer\XmlAttribute
     */
    private $suggestions;

    public function __construct(array $suggestions, $key=null)
    {
        if (!empty($key)) {
            $tmp = $suggestions;
            $suggestions = array();
            for($i=0;$i<count($tmp);$i++) {
                $suggestions[]=array($key=>$tmp[$i]);
            }
        }

        $this->suggestions = $suggestions;
    }
}