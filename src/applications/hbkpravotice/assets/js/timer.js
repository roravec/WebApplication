document.addEventListener("DOMContentLoaded", function () 
{
	let tableHeadWriten = false;
	// Check if the div with id "countdown" exists on the page
	const countdownElement = document.getElementById("countdown");
	const timerContainerElement = document.getElementById("timer-container");
	if (countdownElement || timerContainerElement) 
	{
	  	// Fetch the timestamp from the server
	  	function fetchTimestamp() 
		{
			// You need to replace the URL with the endpoint that fetches the timestamp from the database
			// Get the current URL and add the HTTP parameter
			const currentUrl = window.location.href;
			var currentURI = window.location.href;
			var baseURL = currentUrl.split('?')[0];
			let apiUrl = "https://www.hbkpravotice.sk/StadiumControl?p=GetTimerCountdown";
			if (countdownElement) // Stadum control
				apiUrl = baseURL + "?p=GetTimerCountdown";
			//console.log("Current URI:", currentURI);
			fetch(apiUrl)
		  		.then(response => response.json())
		  		.then(data => 
			{
				// Check if the countdown object is not empty
				if (Object.keys(data).length !== 0) 
				{
					//addHeadToTable("ID", "Time remaining", "Player and reason");
					console.log("Countdown data:", data);
					tableHeadWriten=false;
					if (timerContainerElement) // timer
						ClearPenalties();
					data.countdownEvents.forEach(event => 
					{
						const currentTime = event.lastRecordedTime;
						const endTime = event.endTimestamp;
						const duration = event.duration;
						const startTime = event.startTimestamp;
						let remainingTime = endTime - currentTime;
						if (startTime == 0)
						{
							remainingTime = duration;
						}
                        // Call the function to add a row for each item in the loop
						if (event.paralelEvent == 1) // penalty
						{
							if (countdownElement) // Stadium control
							{
								addRowToTable(event.event, remainingTime, event.name);
							}
							else // timer
							{
								if (event.name.includes(data.team1name))
								{
									AddPenaltyTeam(remainingTime,1);
								}
								else if (event.name.includes(data.team2name))
								{
									AddPenaltyTeam(remainingTime,2);
								}
							}
						}
						else
						{
							if (countdownElement) // Stadium control
							{
								updateCountdown(remainingTime, event, data.timerActive);
							}
							else // timer
							{
								UpdateTimer(
									remainingTime, 
									event.name, 
									data.team1score,
									data.team2score,
									data.team1name,
									data.team2name
									);
							}
						}
                    });
				}
  
			// Update every second
			setTimeout(fetchTimestamp, 500);
		  	})
		  	.catch(error => console.error("Error fetching timestamp:", error));
	  	}

		function UpdateTimer(timeRemaining, note, team1score, team2score, team1name, team2name)
		{
			let remainingTime = timeRemaining;
			if (remainingTime <= 0) 
			{
				remainingTime = "00:00";
			}
			else
			{
				const hours = Math.floor(remainingTime / 3600);
				const minutes = Math.floor((remainingTime % 3600) / 60);
				const seconds = remainingTime % 60;

				// Update the countdown display
				remainingTime = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
			}
			var description = document.getElementById("description");
			var team1scoreElement = document.getElementById("team1-score");
			var team2scoreElement = document.getElementById("team2-score");
			var team1nameElement = document.getElementById("team1-name");
			var team2nameElement = document.getElementById("team2-name");
			timerContainerElement.innerHTML = remainingTime;
			description.innerHTML = note;
			team1scoreElement.innerHTML = parseInt(team1score, 10);
			team2scoreElement.innerHTML = parseInt(team2score, 10);
			if (team1nameElement != null)
				team1nameElement.innerHTML = team1name;
			if (team2nameElement != null)
				team2nameElement.innerHTML = team2name;
		}
		function AddPenaltyTeam(timeRemaining, teamnum)
		{
			let remainingTime = timeRemaining;
			if (remainingTime <= 0) 
			{
				//remainingTime = "00:00";
				return;
			}
			else
			{
				const hours = Math.floor(remainingTime / 3600);
				const minutes = Math.floor((remainingTime % 3600) / 60);
				const seconds = remainingTime % 60;

				// Update the countdown display
				remainingTime = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
			}
			var teamPenalties = document.getElementById("team1-penalties");
			if (teamnum == 2)
				teamPenalties = document.getElementById("team2-penalties");
			teamPenalties.innerHTML = teamPenalties.innerHTML+'<p>'+remainingTime+'</p>';
		}
		function ClearPenalties()
		{
			var team1Penalties = document.getElementById("team1-penalties");
			var team2Penalties = document.getElementById("team2-penalties");
			team1Penalties.innerHTML = '';
			team2Penalties.innerHTML = '';
		}
  
	  	// Update the countdown display and apply styles
	  	function updateCountdown(remainingTime, countdown, timerStatus) 
	  	{
			// Get the button element by its ID
			var button = document.getElementById("TimerControlBtn");

			// Change the text using innerHTML
			if (timerStatus)
			{
				button.innerHTML = "STOP";
			}
			else
			{
				button.innerHTML = "START";
			}
			if (remainingTime <= 0) 
			{
				countdownElement.innerHTML = "00:00";
			}
			else
			{
				const hours = Math.floor(remainingTime / 3600);
				const minutes = Math.floor((remainingTime % 3600) / 60);
				const seconds = remainingTime % 60;
  
				// Update the countdown display
				countdownElement.innerHTML = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
			}

			const table = document.getElementById("eventTableSequence");
			const tableBody = document.getElementById('eventPenalties');
                    tableBody.innerHTML = '';
		  	// Clear previous styles
		  	clearTableRowStyles(table);

		  	// Check if the "event" data matches the current row's "event" column
		  	if (countdown.event) 
			{
				// Find the table row with the matching event value
				const rows = table.getElementsByTagName("tbody")[0].getElementsByTagName("tr");
				//console.log("Table:", table);
				//console.log("Rows:", rows);
				for (let i = 0; i < rows.length; i++) 
				{
					// Remove "> " from the beginning of the content in the second column
					rows[i].cells[1].innerText = rows[i].cells[1].innerText.replace(/^> /, '');
					// Assuming the content of the first column is a string representing a number
					const rowEvent = parseInt(rows[i].cells[0].innerText, 10);
					const countdownEvent = parseInt(countdown.event, 10);

					//console.log("1Current row:", rowEvent);
					//console.log("Coundown event:", countdown.event);
					//console.log("Coundown eventP:", countdownEvent);
					// Compare the converted rowEvent with countdown.event
					if (!isNaN(rowEvent) && rowEvent === countdownEvent) 
					{
					// Apply bold style to the row
						//console.log("2Current row:", rowEvent);
						rows[i].cells[1].innerText = '> ' + rows[i].cells[1].innerText;
						rows[i].style.fontWeight = "bold";
					}
				}
			}
		}
		// Update the countdown display and apply styles
		function addRowToTable(timereventid, timeRemaining, name) 
		{
			if (!tableHeadWriten)
			{
				addHeadToTable("ID", "Time", "Player and reason");
				tableHeadWriten = true;
			}
			let remainingTime = timeRemaining;
			if (remainingTime <= 0) 
			{
				remainingTime = "00:00";
			}
			else
			{
				const hours = Math.floor(remainingTime / 3600);
				const minutes = Math.floor((remainingTime % 3600) / 60);
				const seconds = remainingTime % 60;

				// Update the countdown display
				remainingTime = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
			}
            const tableBody = document.getElementById('eventPenalties');
            const newRow = tableBody.insertRow();

	// 		const deleteLink = `
    // [<a href="?p=overview>&id=${timereventid}&penaltyTimer=1&delete=1"
    //     onclick="return confirm('Are you sure you want to delete the entry?')"
    // >x</a>]`;
	//let deleteLink2 = `<a href="?p=overview>&id=${timereventid}&penaltyTimer=1&delete=1">x</a>`;

            // Add cells with data from CountdownEvent
            const cell1 = newRow.insertCell(0);
            const cell2 = newRow.insertCell(1);
            const cell3 = newRow.insertCell(2);
            //const cell4 = newRow.insertCell(3);

            cell1.textContent = timereventid;
            cell2.textContent = remainingTime;
            cell3.textContent = name;
			cell2.style.fontWeight = "bold";
            //cell4.textContent = deleteLink2;
        }

		function addHeadToTable(timereventid, timeRemaining, name) 
		{
			const tableBody = document.getElementById('eventPenalties');
            const newRow = tableBody.insertRow();
			newRow.style.fontWeight = "bold";
            const cell1 = newRow.insertCell(0);
            const cell2 = newRow.insertCell(1);
            const cell3 = newRow.insertCell(2);

            cell1.textContent = timereventid;
            cell2.textContent = timeRemaining;
            cell3.textContent = name;
        }
  
		// Clear previous styles from all table rows
		function clearTableRowStyles(table) {
		const rows = table.getElementsByTagName("tbody")[0].getElementsByTagName("tr");
		for (let i = 0; i < rows.length; i++) 
		{
			// Reset style for all rows
			rows[i].style.fontWeight = "normal";
			}
		}
	
		// Start the countdown
		fetchTimestamp();
	} // end if (countdownElement) 
});