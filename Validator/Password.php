<?php

namespace Bundle\DoctrineUserBundle\Validator;

use Symfony\Component\Validator\Constraint;

class Password extends Constraint
{
    public $message = 'The entered password is invalid.';
    public $passwordProperty;
    public $userProperty;

    public function requiredOptions()
    {
        return array('passwordProperty');
    }
    
    public function validatedBy()
    {
        return 'doctrine_user.validator.password';
    }
}