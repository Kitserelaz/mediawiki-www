( function () {
    // Use setInterval() to call function after every 1 sec
    $('.countDownClock').each(function(i, e) {
        let x = setInterval(
            function () {
                let endTime = e.attributes.data.value;
                let countDownDate = new Date( endTime ).getTime();

                // Get current date and time
                let currentTime = new Date().getTime();

                // Find the distance between now and the count down date
                let remainingTime = countDownDate - currentTime;

                // Time calculations for days, hours, minutes and seconds
                let days = Math.floor(remainingTime / (1000 * 60 * 60 * 24));
                let hours = Math.floor(( remainingTime % (1000 * 60 * 60 * 24) ) / (1000 * 60 * 60));
                let minutes = Math.floor(( remainingTime % (1000 * 60 * 60) ) / (1000 * 60));
                let seconds = Math.floor(( remainingTime % (1000 * 60) ) / 1000);

                // Output the result in an element with id="countDownClock"
                e.innerText = (
                    mw.message( 'countDownClock-days', days ).text() + ' ' + mw.message( 'countDownClock-hours', hours ).text() + ' ' +
                    mw.message( 'countDownClock-minutes', minutes ).text() + ' ' + mw.message( 'countDownClock-seconds', seconds ).text()
                );

                // If the count down is over, write expired msg
                if (remainingTime < 0 ) {
                    clearInterval(x);
                    e.innerText = mw.message('countDownClock-expired');
                }
            }, 1000
        );
    });
}() );
