<?php

beforeEach(function () {

$this->query = /* @lang GraphQL */
<<<'GRAPHQL'
{
query MyQuery {
  products {
    id
  }
}
}
GRAPHQL;
});


it('test types product', function () {


});
