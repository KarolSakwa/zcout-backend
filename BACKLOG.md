TERAZ
- wytypowanie ostatecznych atrybutów - gracze z pola i bramkarze osobno


NASTĘPNE
- dodanie ikon atrybutów
 
PÓŹNIEJ

BUGI
- zbyt długie nazwiska - określić liczbę znaków i łamać w dwie linie
- confidence w profilu liczy się z jednego atrybutu zamiast jakoś ze wszystkich
- czasami jest taka sytuacja, że po oddaniu głosu obaj piłkarze dostają np. +5 punktów ratingu - trzeba zbadać czy po zmianie atrybutów problem nadal wynika i z czego
![img.png](img.png)
- Logo musi być przezroczyste
- Jest lekkie przeskakiwanie/niepotrzebne wielokrotne przekierowywanie na log in i log out

POMYSŁY
- wyjątkowe stylowanie ratingu w całej aplikacji
- możliwość pomijania pary (nie znam tych piłkarzy), uniemożliwić wylosowanie nowej pary jeśli nie zagłosowało się w obecnej
- rozwiązanie kwestii losowości kliknięć: początkowo wpuszczam 10-20 zaufanych ekspertów + ja. Wagi głosów - wysokie. Dostajemy szkielet ratingów. Na podstawie głosów usera budujemy jego shadow reputation - jeśli jest bardzo zła, ma malutki wpływ.
- sposób przechowywania dużych ilości danych historycznych - będziemy chcieli umożliwiać np. porównanie dwóch piłkarzy w atrybucie szybkość z maja 2026
- zmiana podejścia do odsłaniania atrybutu - może coś jakby odwrócenie kart i na nich wartość atrybutu/overall? Do przemyślenia
- ocena wystepów piłkarzy w konkretnych meczach
