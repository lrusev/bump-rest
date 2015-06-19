<?php
namespace Bump\RestBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation\ExclusionPolicy;
use JMS\Serializer\Annotation\Expose;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * //ORM\Table(name="settings", options={"engine"="InnoDB"})
 * //ORM\Entity(repositoryClass="Bump\RestBundle\Entity\SettingRepository")
 *
 * @ORM\MappedSuperclass
 * @ExclusionPolicy("all")
 * @Serializer\XmlRoot("settings")
 *
 *
 * @UniqueEntity("name")
 * @UniqueEntity("slug")
 */
class Setting
{
    /**
     * @var string
     * @ORM\Id
     * @ORM\Column(name="name", type="string", nullable=false, unique=true)
     * @Assert\NotBlank
     * @Expose
     */
    protected $name;
    /**
     * @var string
     * @ORM\Column(name="slug", type="string", nullable=false, unique=true)
     * @Gedmo\Slug(fields={"name"})
     * @Assert\NotBlank
     * @Expose
     */
    protected $slug;

    /**
     * @var string
     * @ORM\Column(name="value", type="string", nullable=true)
     * @Expose
     */
    protected $value;

    /**
     * @var string
     * @ORM\Column(name="section", type="string", nullable=true)
     * @Expose
     */
    protected $section;

    /**
     * Set name
     *
     * @param string $name
     *
     * @return Setting
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set value
     *
     * @param string $value
     *
     * @return Setting
     */
    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Get value
     *
     * @return string
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set section
     *
     * @param string $section
     *
     * @return Setting
     */
    public function setSection($section)
    {
        $this->section = $section;

        return $this;
    }

    /**
     * Get section
     *
     * @return string
     */
    public function getSection()
    {
        return $this->section;
    }

    /**
     * Set slug
     *
     * @param string $slug
     *
     * @return Setting
     */
    public function setSlug($slug)
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * Get slug
     *
     * @return string
     */
    public function getSlug()
    {
        return $this->slug;
    }
}
