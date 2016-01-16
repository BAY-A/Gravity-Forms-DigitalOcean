function checkDomainName() {
    if(jQuery('#isenabled').attr('checked') && jQuery('#domainenabled').attr('checked')) {
        return true;
    } else {
        return false;
    }
}