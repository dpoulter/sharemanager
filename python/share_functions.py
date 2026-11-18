# insert_statistic is all this module provides. get_key_ratios lived here too,
# a yfinance call that nothing invoked, and its import pulled yfinance into
# every script that imported this one - including get_api_stats.py, which then
# could not start on a server without it.
from functions import insert, query

def insert_statistic (symbol,exchange,indicator,date,value):
    try:
            
        rows=query("select symbol from statistics where symbol='"+symbol+"' and exchange='"+exchange+"' and indicator='"+indicator+"'and date='"+date+"'")
    
        if (rows):
            query("update statistics set value='"+str(value)+"' where symbol='"+symbol+"' and exchange='"+exchange+"' and indicator='"+indicator+"'and date='"+date+"'")
			
        else:
            insert("insert into statistics (symbol, exchange, indicator, value,date) values ('"+symbol+"','"+exchange+"','"+indicator+"','"+str(value)+"','"+date+"')")
            

    except Exception as error:
        print("Error in insert_statistic:"+str(error))
        return None
