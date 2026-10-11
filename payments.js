async function addBalance() {
    // 1. Поиск элементов
    const amountInput = document.getElementById('topup-amount');
    // Ищем кнопку более универсально, на случай если атрибут onclick изменится
    const payBtn = document.querySelector('button[onclick="addBalance()"]') || document.activeElement;
    
    // Получаем ID и сумму
    const userId = localStorage.getItem('user_id'); 
    const amount = amountInput ? amountInput.value : 0;

    // 2. Валидация
    if (!amount || amount < 10) {
        alert("Минимальная сумма пополнения — 10 ₽");
        return;
    }

    if (!userId) {
        alert("Ошибка: пользователь не авторизован. Пожалуйста, выйдите и войдите в аккаунт снова.");
        return;
    }

    // 3. Визуальная блокировка кнопки
    const originalText = payBtn ? payBtn.innerText : "Оплатить";
    if (payBtn) {
        payBtn.disabled = true;
        payBtn.innerHTML = '<i class="ph ph-circle-notch animate-spin"></i> Создание счета...';
    }

    try {
        // 4. Запрос на бэкенд
        const response = await fetch('/api/create_invoice.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json' 
            },
            body: JSON.stringify({ 
                amount: parseFloat(amount),
                // Отправляем ID как число для корректной работы с БД
                user_id: Number(userId) 
            })
        });

        // Проверка ответа сервера
        if (!response.ok) {
            throw new Error(`Ошибка сервера: ${response.status}`);
        }

        const result = await response.json();

        if (result.success && result.url) {
            // Успех — редирект на платежный шлюз
            window.location.href = result.url;
        } else {
            // Ошибка от API (например, неверная подпись)
            alert("Ошибка: " + (result.message || "Не удалось сформировать счет"));
            resetButton(payBtn, originalText);
        }

    } catch (e) {
        console.error("Payment Error:", e);
        alert("Техническая ошибка при создании платежа. Проверьте соединение.");
        resetButton(payBtn, originalText);
    }
}

/**
 * Вспомогательная функция для возврата кнопки в рабочее состояние
 */
function resetButton(btn, text) {
    if (btn) {
        btn.disabled = false;
        btn.innerText = text;
    }
}