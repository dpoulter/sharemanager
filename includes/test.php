<?php

class DateTimeEnhanced extends DateTime {

    public function returnAdd(DateInterval $interval)
    {
        $dt = clone $this;
        $dt->add($interval);
        return $dt;
    }
   
    public function returnSub(DateInterval $interval)
    {
        $dt = clone $this;
        $dt->sub($interval);
        return $dt;
    }

}

$interval = DateInterval::createfromdatestring('+1 day');

$dt = new DateTimeEnhanced; # initialize new object
echo $dt->format(DateTime::W3C) . "\n"; # 2013-09-12T15:01:44+02:00

$dt->add($interval); # this modifies the object values
echo $dt->format(DateTime::W3C) . "\n"; # 2013-09-13T15:01:44+02:00

$dtNew = $dt->returnAdd($interval); # this returns the new modified object and doesn't change original object
echo $dt->format(DateTime::W3C) . "\n"; # 2013-09-13T15:01:44+02:00
echo $dtNew->format(DateTime::W3C) . "\n"; # 2013-09-14T15:01:44+02:00