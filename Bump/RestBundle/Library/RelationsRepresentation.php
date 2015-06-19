<?php
namespace Bump\RestBundle\Library;

use Hateoas\Configuration\Relation;
use Hateoas\Configuration\Annotation as Hateoas;
use Hateoas\Configuration\Metadata\ClassMetadataInterface;
use JMS\Serializer\Annotation as Serializer;

/**
 * @Serializer\ExclusionPolicy("all")
 * @Serializer\XmlRoot("resource")
 *
 * @Hateoas\RelationProvider("getRelations")
 *
 */
class RelationsRepresentation {

     /**
     * @Serializer\Expose
     */
    private $date;

    /**
     * @Serializer\Expose
     */
    private $support;

     /**
     * @Serializer\Expose
     */
    private $about;

    private $relations=array();

    public function __construct(array $relations=array())
    {
        $this->date = new \DateTime();
        $this->setRelations($relations);
    }

    public function setRelations(array $relations)
    {
        $this->relations = array();
        foreach($relations as $relation) {
            $this->addRelation($relation);
        }

        return $this;
    }

    public function addRelation(Relation $relation)
    {
        $this->relations[] = $relation;

        return $this;
    }

    public function getRelations($object, ClassMetadataInterface $classMetadata)
    {
        return $this->relations;
    }

    public function setAbout($about)
    {
        $this->about = $about;
        return $this;
    }

    public function getAbout()
    {
        return $this->about;
    }

    public function setSupport($support)
    {
        $this->support = $support;

        return $this;
    }

    public function getSupport()
    {
        return $this->support;
    }
}