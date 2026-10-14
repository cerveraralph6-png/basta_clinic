
        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('active');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('active');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }
        }

        document.getElementById('openLoginModal').addEventListener('click', () => openModal('loginModal'));
        document.getElementById('openRegisterModal').addEventListener('click', () => openModal('registerModal'));
        document.getElementById('openScheduleModal').addEventListener('click', () => {
            openModal('scheduleModal');
            renderCalendar();
        });

        document.getElementById('switchToRegister').addEventListener('click', (e) => {
            e.preventDefault();
            closeModal('loginModal');
            openModal('registerModal');
        });

        document.getElementById('switchToLogin').addEventListener('click', (e) => {
            e.preventDefault();
            closeModal('registerModal');
            openModal('loginModal');
        });

        document.querySelectorAll('[data-close]').forEach(btn => {
            btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close')));
        });

        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeModal(overlay.id);
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(overlay => closeModal(overlay.id));
            }
        });

        const form = document.getElementById('registrationForm');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirmPassword');
        const passwordError = document.getElementById('passwordError');

        form.addEventListener('submit', (e) => {
            if (password.value !== confirmPassword.value) {
                e.preventDefault();
                passwordError.style.display = 'block';
                confirmPassword.focus();
            } else {
                passwordError.style.display = 'none';
            }
        });

        let currentDate = new Date();
        let selectedDate = new Date();

        const sampleAppointments = {
            3: [
                { time: '09:00 AM', title: 'Dr. Michael Chen', dept: 'Cardiology' },
                { time: '11:30 AM', title: 'Dr. Sarah Connor', dept: 'Pediatrics' }
            ],
            7: [
                { time: '02:00 PM', title: 'Dr. Emily Vance', dept: 'Dermatology' }
            ],
            14: [
                { time: '08:30 AM', title: 'Dr. Alex Stone', dept: 'Orthopedics' },
                { time: '10:00 AM', title: 'Dr. Lisa Ray', dept: 'Dental Care' },
                { time: '03:15 PM', title: 'Dr. John Watson', dept: 'General Checkup' }
            ],
            21: [
                { time: '01:00 PM', title: 'Dr. Michael Chen', dept: 'Cardiology' }
            ]
        };

        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

        function renderCalendar() {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();

            document.getElementById('calendarMonthYear').innerText = `${monthNames[month]} ${year}`;

            const firstDayIndex = new Date(year, month, 1).getDay();
            const lastDay = new Date(year, month + 1, 0).getDate();

            const calendarDays = document.getElementById('calendarDays');
            calendarDays.innerHTML = '';

            for (let i = 0; i < firstDayIndex; i++) {
                const emptyDiv = document.createElement('div');
                emptyDiv.classList.add('calendar-day', 'empty');
                calendarDays.appendChild(emptyDiv);
            }

            const today = new Date();

            for (let day = 1; day <= lastDay; day++) {
                const dayDiv = document.createElement('div');
                dayDiv.classList.add('calendar-day');
                dayDiv.innerText = day;

                if (day === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
                    dayDiv.classList.add('today');
                }

                if (day === selectedDate.getDate() && month === selectedDate.getMonth() && year === selectedDate.getFullYear()) {
                    dayDiv.classList.add('selected');
                }

                if (sampleAppointments[day]) {
                    dayDiv.classList.add('has-event');
                }

                dayDiv.addEventListener('click', () => {
                    selectedDate = new Date(year, month, day);
                    renderCalendar();
                    updateAgenda(day);
                });

                calendarDays.appendChild(dayDiv);
            }

            updateAgenda(selectedDate.getDate());
        }

        function updateAgenda(day) {
            const dateText = document.getElementById('selectedDateText');
            const agendaList = document.getElementById('agendaList');
            const formatted = `${monthNames[selectedDate.getMonth()]} ${day}, ${selectedDate.getFullYear()}`;
            
            dateText.innerText = `Slots for: ${formatted}`;
            agendaList.innerHTML = '';

            const dayEvents = sampleAppointments[day];

            if (dayEvents && dayEvents.length > 0) {
                dayEvents.forEach(evt => {
                    const item = document.createElement('div');
                    item.className = 'agenda-item';
                    item.innerHTML = `
                        <h6>${evt.title}</h6>
                        <span>${evt.time} &bull; ${evt.dept}</span>
                    `;
                    agendaList.appendChild(item);
                });
            } else {
                agendaList.innerHTML = `
                    <div style="text-align: center; color: var(--text-muted); padding: 1.5rem 0;">
                        No appointments scheduled for this day.
                    </div>
                `;
            }
        }

        document.getElementById('prevMonthBtn').addEventListener('click', () => {
            currentDate.setMonth(currentDate.getMonth() - 1);
            renderCalendar();
        });

        document.getElementById('nextMonthBtn').addEventListener('click', () => {
            currentDate.setMonth(currentDate.getMonth() + 1);
            renderCalendar();
        });